<?php

namespace Modules\PayrollModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HrModule\app\Models\Staff;
use Modules\PayrollModule\app\Models\AdjustmentType;
use Modules\PayrollModule\app\Models\EmployeeAdjustment;
use Modules\PayrollModule\app\Services\Concerns\PayrollHelpers;

/**
 * One-off bonuses / deductions of a staff file (docs/hr-plan.md §3.5, H4): pending items of their month for payroll (H5 reads
 * the rows with payroll_line_id 0). Cancel = soft delete with the reason (refused once payroll used it). Logged in employee_events.
 */
class AdjustmentService
{
    use PayrollHelpers;

    // every row of the person, the cancelled ones too, newest month first
    public function list(Staff $staff): Collection
    {
        return EmployeeAdjustment::withTrashed()->with('type', 'creatable.userable')->where('account_id', $staff->account_id)
            ->where('employee_id', $staff->id)->orderByDesc('period')->orderByDesc('id')->get();
    }

    public function find(Staff $staff, $id): EmployeeAdjustment
    {
        return EmployeeAdjustment::with('type')->where('account_id', $staff->account_id)->where('employee_id', $staff->id)->findOrFail($id);
    }

    // the form: active types by kind [kind label => [id => name]]
    public function typeOptions($accountId): array
    {
        $options = [];
        foreach (AdjustmentType::where('account_id', $accountId)->where('is_active', true)->orderByLocalized()->get() as $type) {
            $options[$type->kind_label][$type->id] = $type->name;
        }
        return $options;
    }

    // $data: adjustment_type_id, period (Y-m-01), value_kind amount | days, amount / days, reason
    public function create(Staff $staff, array $data, Model $user): EmployeeAdjustment
    {
        $type = AdjustmentType::where('account_id', $staff->account_id)->where('is_active', true)->find((int) ($data['adjustment_type_id'] ?? 0));
        if (!$type) {
            throw ValidationException::withMessages(['adjustment_type_id' => 'Choose a bonus / deduction type (HR › Adjustment Types).']);
        }
        $period = $this->month($data['period'] ?? null);
        if (!$period) {
            throw ValidationException::withMessages(['period' => 'Choose the month.']);
        }
        $byDays = ($data['value_kind'] ?? 'amount') === 'days';
        $value = round((float) ($byDays ? ($data['days'] ?? 0) : ($data['amount'] ?? 0)), 2);
        if ($value <= 0) {
            throw ValidationException::withMessages([$byDays ? 'days' : 'amount' => $byDays ? 'Write the number of days.' : 'Write the amount.']);
        }
        if ($byDays && fmod($value * 2, 1) != 0) {
            throw ValidationException::withMessages(['days' => 'Days in half-day steps (0.5, 1, 1.5 …).']);
        }

        return DB::transaction(function () use ($staff, $type, $period, $byDays, $value, $data, $user) {
            $adjustment = new EmployeeAdjustment([
                'account_id' => $staff->account_id, 'employee_id' => $staff->id, 'adjustment_type_id' => $type->id,
                'period' => $period->toDateString(), 'amount' => $byDays ? null : $value, 'days' => $byDays ? $value : null,
                'reason' => trim($data['reason']),
            ]);
            $adjustment->creatable()->associate($user);
            $adjustment->save();
            $this->event($staff, 'adjustment_added', [
                'type' => [null, $type->kind_label . ' · ' . $type->name], 'month' => [null, $period->format('F Y')], 'value' => [null, $adjustment->valueText()],
            ], $adjustment->reason, $user);
            return $adjustment;
        });
    }

    public function cancel(Staff $staff, $id, string $reason, Model $user): EmployeeAdjustment
    {
        $adjustment = $this->find($staff, $id);
        if ($adjustment->payroll_line_id) {
            throw ValidationException::withMessages(['cancel_reason' => 'Payroll already used it: add a correction in an open month instead.']);
        }
        return DB::transaction(function () use ($staff, $adjustment, $reason, $user) {
            $adjustment->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
            $adjustment->delete();
            $this->event($staff, 'adjustment_cancelled', [
                'type' => [$adjustment->type?->kind_label . ' · ' . $adjustment->type?->name, null], 'month' => [$adjustment->period->format('F Y'), null], 'value' => [$adjustment->valueText(), null],
            ], $reason, $user);
            return $adjustment;
        });
    }
}
