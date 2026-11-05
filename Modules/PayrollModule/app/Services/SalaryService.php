<?php

namespace Modules\PayrollModule\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HrModule\app\Models\EmployeeEvent;
use Modules\HrModule\app\Models\Staff;
use Modules\HrModule\app\Services\StaffService;
use Modules\PayrollModule\app\Models\AllowanceType;
use Modules\PayrollModule\app\Models\EmployeeInsurance;
use Modules\PayrollModule\app\Models\EmployeeSalary;
use Modules\PayrollModule\app\Models\PayrollSetting;
use Modules\PayrollModule\app\Repositories\EmployeeSalaryRepository;

/**
 * Salaries + social insurance of a staff file (docs/hr-plan.md §3.3, H2; the Salary tab). Every method works on a staff file of
 * the account (staff() = 404 for another account's file or an External one: not on payroll). Rule errors = ValidationException
 * (422 under the fields). Every change writes an employee_events line (salary_* / insurance_changed: shown only with hr.salary).
 */
class SalaryService
{
    public function __construct(private EmployeeSalaryRepository $salaryRepository)
    {
    }

    // the account's staff file on payroll (External = 404)
    public function staff($accountId, $id): Staff
    {
        $staff = app(StaffService::class)->findOne($accountId, $id);
        abort_if($staff->is_external, 404);
        return $staff;
    }

    // every salary row, newest first (the history table; the first one in force today = current())
    public function history(Staff $staff): Collection
    {
        return $this->salaryRepository->forAccount($staff->account_id)->with('creatable.userable')
            ->where('employee_id', $staff->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
    }

    // the salary in force on $day (default today): the latest effective_from ≤ the day
    public function current(Collection $history, ?Carbon $day = null): ?EmployeeSalary
    {
        $day = ($day ?? Carbon::today())->copy()->startOfDay();
        return $history->first(fn ($salary) => $salary->effective_from->lte($day));
    }

    public function find(Staff $staff, $salaryId): EmployeeSalary
    {
        return $this->salaryRepository->forAccount($staff->account_id)->where('employee_id', $staff->id)->findOrFail($salaryId);
    }

    // the form's allowance fields: the active types + the ones already on $salary, by name
    public function allowanceTypes($accountId, ?EmployeeSalary $salary = null): Collection
    {
        $ids = $salary ? $salary->items->pluck('allowance_type_id')->all() : [];
        return AllowanceType::withTrashed()->where('account_id', $accountId)
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))->orWhereIn('id', $ids ?: [0]))
            ->orderByLocalized()->get();
    }

    // a new salary from a date (a raise = a new row; the old ones are kept)
    public function create(Staff $staff, array $data, Model $user): EmployeeSalary
    {
        return DB::transaction(function () use ($staff, $data, $user) {
            $this->lock($staff);
            $items = $this->checked($staff, $data);
            $salary = new EmployeeSalary(['employee_id' => $staff->id] + $this->fields($data));
            $salary->account_id = $staff->account_id;
            $salary->creatable()->associate($user);
            $salary->save();
            $this->saveItems($salary, $items);
            $this->event($staff, 'salary_added', $this->diff([], $this->snapshot($salary->fresh('items.type'))), $salary->reason, $user);
            return $salary;
        });
    }

    // the locked payroll months that used this salary row ("October 2026, November 2026"), '' when none
    public function lockedUse(EmployeeSalary $salary): string
    {
        return \Modules\PayrollModule\app\Models\PayrollLineItem::where('source', 'salary')->where('source_id', $salary->id)
            ->join('payroll_lines', 'payroll_lines.id', '=', 'payroll_line_items.line_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.run_id')->where('payroll_runs.status', 'locked')
            ->orderBy('payroll_runs.period')->pluck('payroll_runs.period')->unique()
            ->map(fn ($period) => Carbon::parse($period)->format('F Y'))->implode(', ');
    }

    private function assertNotLocked(EmployeeSalary $salary, string $field): void
    {
        if ($used = $this->lockedUse($salary)) {
            throw ValidationException::withMessages([$field => 'A locked payroll used this salary (' . $used . '): add a new salary instead.']);
        }
    }

    // fix a salary row (refused once a locked payroll used it)
    public function update(Staff $staff, $salaryId, array $data, Model $user): EmployeeSalary
    {
        return DB::transaction(function () use ($staff, $salaryId, $data, $user) {
            $this->lock($staff);
            $salary = $this->find($staff, $salaryId);
            $this->assertNotLocked($salary, 'effective_from');
            $before = $this->snapshot($salary);
            $items = $this->checked($staff, $data, $salary);
            $salary->fill($this->fields($data))->save();
            $salary->items()->delete();
            $this->saveItems($salary, $items);
            $changes = $this->diff($before, $this->snapshot($salary->fresh('items.type')));
            if ($changes) {
                $this->event($staff, 'salary_edited', ['salary from' => $before['effective_from']] + $changes, null, $user);
            }
            return $salary;
        });
    }

    // soft delete of a row typed by mistake (its items stay with it)
    public function delete(Staff $staff, $salaryId, Model $user): void
    {
        DB::transaction(function () use ($staff, $salaryId, $user) {
            $salary = $this->find($staff, $salaryId);
            $this->assertNotLocked($salary, 'salary');
            $this->event($staff, 'salary_deleted', $this->diff($this->snapshot($salary), []), null, $user);
            $salary->delete();
        });
    }

    // ------------------------------------------------------------------ insurance

    // the saved row, else an empty one (not saved)
    public function insurance(Staff $staff): EmployeeInsurance
    {
        return EmployeeInsurance::where('employee_id', $staff->id)->first()
            ?? new EmployeeInsurance(['account_id' => $staff->account_id, 'employee_id' => $staff->id, 'is_insured' => false]);
    }

    public function saveInsurance(Staff $staff, array $data, Model $user): EmployeeInsurance
    {
        return DB::transaction(function () use ($staff, $data, $user) {
            $insurance = $this->insurance($staff);
            $before = $insurance->exists ? $this->insuranceSnapshot($insurance) : [];
            $insured = !empty($data['is_insured']);
            $insurance->fill([
                'is_insured' => $insured,
                'insurance_no' => $data['insurance_no'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'insured_wage' => isset($data['insured_wage']) && $data['insured_wage'] !== '' ? round((float) $data['insured_wage'], 2) : null,
                'end_date' => $data['end_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $insurance->account_id = $staff->account_id;
            $insurance->employee_id = $staff->id;
            $insurance->save();
            $changes = $this->diff($before, $this->insuranceSnapshot($insurance));
            if ($changes) {
                $this->event($staff, 'insurance_changed', $changes, null, $user);
            }
            return $insurance;
        });
    }

    // [employee share, company share] of the insured wage by the settings' % (null = not insured / no wage / % not set)
    public function shares(EmployeeInsurance $insurance, PayrollSetting $settings): ?array
    {
        if (!$insurance->is_insured || !$insurance->insured_wage || !$settings->insuranceSet()) {
            return null;
        }
        return [
            round($insurance->insured_wage * $settings->insurance_employee_percent / 100, 2),
            round($insurance->insured_wage * $settings->insurance_company_percent / 100, 2),
        ];
    }

    // the Salary tab's warnings (never blocks, docs/hr-plan.md §B), by card: ['salary' => [...], 'insurance' => [...]]
    public function warnings(?EmployeeSalary $current, EmployeeInsurance $insurance, PayrollSetting $settings): array
    {
        $warnings = ['salary' => [], 'insurance' => []];
        if (!$current) {
            $warnings['salary'][] = 'No salary in force today.';
        }
        if ($insurance->is_insured) {
            if (!$insurance->insurance_no) {
                $warnings['insurance'][] = 'Insured, but the insurance number is missing.';
            }
            if (!$insurance->insured_wage) {
                $warnings['insurance'][] = 'Insured, but the insured wage is missing.';
            } elseif ($current && abs($insurance->insured_wage - $current->insurable) >= 0.01) {
                $warnings['insurance'][] = 'The insured wage (' . number_format($insurance->insured_wage, 2) . ') differs from the insurable salary (' . number_format($current->insurable, 2) . ').';
            }
            if (!$settings->insuranceSet()) {
                $warnings['insurance'][] = 'The insurance percentages are not set yet (HR › Payroll Settings).';
            }
        }
        return $warnings;
    }

    // everything the Salary tab shows
    public function tabData(Staff $staff): array
    {
        $history = $this->history($staff);
        $current = $this->current($history);
        $insurance = $this->insurance($staff);
        $settings = PayrollSetting::of($staff->account_id);
        return [
            'salaries' => $history,
            'current' => $current,
            // a row starting later (a raise typed in advance)
            'upcoming' => $history->filter(fn ($salary) => $salary->effective_from->isFuture())->sortBy('effective_from')->first(),
            'insurance' => $insurance,
            'settings' => $settings,
            'shares' => $this->shares($insurance, $settings),
            'warnings' => $this->warnings($current, $insurance, $settings),
        ];
    }

    // ------------------------------------------------------------------ helpers

    // one save at a time per person (the effective_from check)
    private function lock(Staff $staff): void
    {
        Staff::whereKey($staff->id)->lockForUpdate()->first();
    }

    private function fields(array $data): array
    {
        return [
            'effective_from' => $data['effective_from'],
            'basic' => round((float) $data['basic'], 2),
            'reason' => $data['reason'] ?? null,
        ];
    }

    // one row per date; the allowance types of the account (active, or already on this row); [type id => amount] of the amounts > 0
    private function checked(Staff $staff, array $data, ?EmployeeSalary $salary = null): array
    {
        $taken = EmployeeSalary::where('employee_id', $staff->id)->whereDate('effective_from', $data['effective_from'])
            ->when($salary, fn ($query) => $query->whereKeyNot($salary->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['effective_from' => 'A salary from this date exists already: edit it instead.']);
        }
        $allowed = $this->allowanceTypes($staff->account_id, $salary)->pluck('id')->all();
        $items = [];
        foreach ((array) ($data['allowances'] ?? []) as $typeId => $amount) {
            if ($amount === null || $amount === '' || (float) $amount == 0) {
                continue;
            }
            if (!in_array((int) $typeId, $allowed, true)) {
                throw ValidationException::withMessages(['allowances.' . $typeId => 'Unknown allowance type.']);
            }
            $items[(int) $typeId] = round((float) $amount, 2);
        }
        return $items;
    }

    private function saveItems(EmployeeSalary $salary, array $items): void
    {
        foreach ($items as $typeId => $amount) {
            $salary->items()->create(['allowance_type_id' => $typeId, 'amount' => $amount]);
        }
    }

    // the logged values of a salary row
    private function snapshot(EmployeeSalary $salary): array
    {
        return [
            'effective_from' => $salary->effective_from->format('d-m-Y'),
            'basic' => number_format($salary->basic, 2),
            'allowances' => $salary->itemsText() ?: null,
            'total' => number_format($salary->total, 2),
            'reason' => $salary->reason,
        ];
    }

    private function insuranceSnapshot(EmployeeInsurance $insurance): array
    {
        return [
            'insured' => $insurance->is_insured ? 'Yes' : 'No',
            'insurance_no' => $insurance->insurance_no,
            'start_date' => $insurance->start_date?->format('d-m-Y'),
            'insured_wage' => $insurance->insured_wage !== null ? number_format($insurance->insured_wage, 2) : null,
            'end_date' => $insurance->end_date?->format('d-m-Y'),
            'notes' => $insurance->notes,
        ];
    }

    // [field => [before, after]] of the values that changed
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[$field] = [$old, $new];
            }
        }
        return $changes;
    }

    private function event(Staff $staff, string $type, array $changes, ?string $reason, Model $user): void
    {
        $event = new EmployeeEvent(['account_id' => $staff->account_id, 'employee_id' => $staff->id, 'type' => $type, 'changes' => $changes ?: null, 'reason' => $reason]);
        $event->creatable()->associate($user);
        $event->save();
    }
}
