<?php

namespace Modules\PayrollModule\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HrModule\app\Models\EmployeeEvent;
use Modules\HrModule\app\Models\EmployeeLeave;
use Modules\HrModule\app\Models\Staff;
use Modules\PaymentModule\app\Models\ExpenseCategory;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\PaymentModule\app\Services\ExpenseService;
use Modules\PayrollModule\app\Models\EmployeeAdjustment;
use Modules\PayrollModule\app\Models\EmployeeAdvance;
use Modules\PayrollModule\app\Models\EmployeeAdvanceMove;
use Modules\PayrollModule\app\Models\EmployeeInsurance;
use Modules\PayrollModule\app\Models\PayrollLine;
use Modules\PayrollModule\app\Models\PayrollPayment;
use Modules\PayrollModule\app\Models\PayrollRun;
use Modules\PayrollModule\app\Models\PayrollSetting;

/**
 * Payroll (docs/hr-plan.md §3.6, §6, H5). ONE month at a time.
 * - calculate(): a draft's lines rebuilt from the data (salaries, bonuses / deductions, unpaid leaves, advance plans, insurance);
 *   READS ONLY — it never changes an advance / bonus / deduction / leave (user 2026-11-06). The typed
 *   income tax + notes of each line are kept.
 * - lock(): recalculates, then freezes: bonuses / deductions stamped (payroll_line_id), each advance gets a `deduct` move.
 * - reopen(): the last locked month, no payment left, reason: the deduct moves cancelled, the stamps removed → draft.
 * - pay() / payAll() / undoPayment(): company Cash Out "Salaries" (outside shifts) — refused until "Salaries" is the system
 *   category (the go-live fix file).
 * Every money value is rounded to the cent; net = gross + bonuses − deductions − unpaid leave − advance − insurance − tax.
 * Fixes before go-live (user 2026-11-06): months before payroll_settings.first_payroll_month are refused (paid by hand); the
 * insurance employee share only while insured in the month and never above the gross; a line with no salary for the month =
 * snapshot no_salary (warned, out of the totals); Lock refused while a line's net is negative (the names listed).
 */
class PayrollService
{
    public function __construct(private SalaryService $salaryService, private AdvanceService $advanceService, private ExpenseService $expenseService)
    {
    }

    // ------------------------------------------------------------------ reading

    public function run($accountId, Carbon $month): ?PayrollRun
    {
        return PayrollRun::where('account_id', $accountId)->whereDate('period', $month->copy()->startOfMonth())->first();
    }

    public function lines(PayrollRun $run): Collection
    {
        return $run->lines()->with('payments.paymentMethod', 'payments.expense')->get()->sortBy(fn ($line) => [$line->snapshot['employee_no'] ?? 9999, $line->id])->values();
    }

    public function findLine(PayrollRun $run, $lineId): PayrollLine
    {
        return $run->lines()->with('items', 'payments.paymentMethod', 'payments.creatable.userable')->findOrFail($lineId);
    }

    // the people on payroll in a month: not External, not deleted, joined by its end, not ended before its start
    public function staffFor($accountId, Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        return Staff::with('position')->where('account_id', $accountId)->where('is_external', false)
            ->where(fn ($query) => $query->whereNull('join_date')->orWhere('join_date', '<=', $end->toDateString()))
            // left before the month = out (an ended status without a date = left long ago)
            ->where(fn ($query) => $query->whereNotIn('status', config('hrmodule.ended_statuses'))
                ->orWhere(fn ($query) => $query->whereNotNull('status_date')->where('status_date', '>=', $start->toDateString())))
            ->orderBy('employee_no')->get();
    }

    // the system category exists = paying from payroll is switched on (go-live)
    public function canPay($accountId): bool
    {
        return ExpenseCategory::where('account_id', $accountId)->where('generated_key', 'salaries')->exists();
    }

    // the first month paid through Payroll (null = not set)
    public function firstMonth($accountId): ?Carbon
    {
        return PayrollSetting::of($accountId)->first_payroll_month?->copy()->startOfMonth();
    }

    // why this month can't go through Payroll (before the first payroll month: paid by hand), null = allowed
    public function monthError($accountId, Carbon $month): ?string
    {
        $first = $this->firstMonth($accountId);
        return $first && $month->copy()->startOfMonth()->lt($first)
            ? $month->format('F Y') . ' is before the first payroll month (' . $first->format('F Y') . '): it was paid by hand.' : null;
    }

    private function assertMonth($accountId, Carbon $month, string $field = 'run'): void
    {
        if ($error = $this->monthError($accountId, $month)) {
            throw ValidationException::withMessages([$field => $error]);
        }
    }

    // the lines whose net is below 0 (Lock is refused while there is one)
    public function negativeLines(PayrollRun $run): Collection
    {
        return $run->lines()->where('net', '<', 0)->get();
    }

    // ------------------------------------------------------------------ calculate

    public function calculate($accountId, Carbon $month, Model $user): PayrollRun
    {
        $month = $month->copy()->startOfMonth();
        $this->assertMonth($accountId, $month);
        return DB::transaction(function () use ($accountId, $month, $user) {
            $run = PayrollRun::where('account_id', $accountId)->whereDate('period', $month)->lockForUpdate()->first();
            if ($run && $run->isLocked()) {
                throw ValidationException::withMessages(['run' => $month->format('F Y') . ' is locked: reopen it first.']);
            }
            if (!$run) {
                $run = new PayrollRun(['account_id' => $accountId, 'period' => $month->toDateString(), 'status' => 'draft']);
                $run->creatable()->associate($user);
                $run->save();
            }
            $this->rebuild($run, $user, 'calculated');
            return $run;
        });
    }

    // the lines from the data now (the typed tax + notes of each person kept)
    private function rebuild(PayrollRun $run, Model $user, string $type): void
    {
        $month = $run->period->copy();
        $settings = PayrollSetting::of($run->account_id);
        $kept = $run->lines()->get()->keyBy('employee_id');
        foreach ($run->lines()->get() as $old) {
            $old->items()->delete();
            $old->delete();
        }
        foreach ($this->staffFor($run->account_id, $month) as $staff) {
            $previous = $kept[$staff->id] ?? null;
            [$values, $items] = $this->compute($staff, $month, $settings, (float) ($previous?->income_tax ?? 0));
            $line = PayrollLine::create($values + ['account_id' => $run->account_id, 'run_id' => $run->id, 'employee_id' => $staff->id, 'notes' => $previous?->notes]);
            foreach ($items as $item) {
                $line->items()->create($item);
            }
        }
        $this->refreshTotals($run);
        $run->calculated_at = now();
        $run->history = array_merge($run->history ?? [], [['type' => $type, 'at' => now()->toDateTimeString(), 'by' => $this->who($user), 'totals' => $run->totals]]);
        $run->save();
    }

    /**
     * One person's line for a month — pure reading. Returns [values, items].
     * @return array{0: array, 1: array}
     */
    public function compute(Staff $staff, Carbon $month, PayrollSetting $settings, float $tax = 0): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $monthDays = max(1, (int) ($settings->month_days ?: 30));
        $items = [];
        $warnings = [];
        $money = fn ($value) => round((float) $value, 2);

        // days in service
        $from = $staff->join_date && $staff->join_date->gt($start) ? $staff->join_date->copy()->startOfDay() : $start->copy();
        $ended = in_array($staff->status, config('hrmodule.ended_statuses'), true) && $staff->status_date ? $staff->status_date->copy()->startOfDay() : null;
        $to = $ended && $ended->lt($end) ? $ended : $end->copy();
        $full = $from->equalTo($start) && $to->equalTo($end);
        $calendar = $from->lte($to) ? $from->diffInDays($to) + 1 : 0;
        $days = $full ? $monthDays : min($calendar, $monthDays);
        if (!$full) {
            $warnings[] = 'Prorated: ' . rtrim(rtrim(number_format($days, 2), '0'), '.') . ' of ' . $monthDays . ' days in service.';
        }

        // salary: each salary row for its days (a full month = shares of the month's calendar days; partial = ÷ month_days)
        $history = $this->salaryService->history($staff)->sortBy('effective_from')->values();
        $basic = 0.0;
        $allowanceByType = [];
        $monthCalendar = $start->daysInMonth;
        foreach ($history as $i => $salary) {
            $next = $history[$i + 1] ?? null;
            $segFrom = $salary->effective_from->copy()->max($from);
            $segTo = $next ? $next->effective_from->copy()->subDay()->min($to) : $to->copy();
            if ($segFrom->gt($segTo)) {
                continue;
            }
            $segDays = $segFrom->diffInDays($segTo) + 1;
            $share = $full ? $segDays / $monthCalendar : $segDays / $monthDays;
            $basic += $salary->basic * $share;
            foreach ($salary->items as $item) {
                $key = $item->allowance_type_id;
                $allowanceByType[$key] = ['label' => $item->type?->name ?? 'Allowance', 'amount' => ($allowanceByType[$key]['amount'] ?? 0) + $item->amount * $share];
            }
        }
        $basic = $money($basic);
        $current = $this->salaryService->current($history->sortByDesc('effective_from')->values(), $to);
        $noSalary = $basic == 0 && !$allowanceByType;
        if ($noSalary) {
            $warnings[] = 'No salary for this month: not paid and not in the totals (add a salary on the staff page, then recalculate).';
        }
        $items[] = ['kind' => 'earning', 'source' => 'salary', 'source_id' => $current?->id ?? 0, 'label' => 'Basic salary', 'quantity' => $full ? null : $days, 'amount' => $basic];
        $allowances = 0.0;
        foreach ($allowanceByType as $typeId => $row) {
            $amount = $money($row['amount']);
            $allowances += $amount;
            $items[] = ['kind' => 'earning', 'source' => 'allowance', 'source_id' => $typeId, 'label' => $row['label'], 'quantity' => null, 'amount' => $amount];
        }
        $allowances = $money($allowances);
        $gross = $money($basic + $allowances);
        $daily = $current ? $current->total / $monthDays : 0.0;

        // bonuses / deductions of the month not used by a locked payroll
        $bonuses = 0.0;
        $deductions = 0.0;
        $adjustments = EmployeeAdjustment::with('type')->where('employee_id', $staff->id)->whereDate('period', $start)->where('payroll_line_id', 0)->get();
        foreach ($adjustments as $adjustment) {
            $amount = $money($adjustment->days !== null ? $adjustment->days * $daily : $adjustment->amount);
            $deduction = $adjustment->isDeduction();
            $deduction ? $deductions += $amount : $bonuses += $amount;
            $items[] = ['kind' => $deduction ? 'deduction' : 'earning', 'source' => 'adjustment', 'source_id' => $adjustment->id,
                'label' => ($adjustment->type?->name ?? '-') . ' · ' . $adjustment->reason, 'quantity' => $adjustment->days, 'amount' => $amount];
        }

        // unpaid leave days inside the month (a leave crossing months: its days by the dates inside)
        $unpaidDays = 0.0;
        $unpaid = 0.0;
        $leaves = EmployeeLeave::with('type')->where('employee_id', $staff->id)->where('from_date', '<=', $end->toDateString())
            ->where('to_date', '>=', $start->toDateString())->whereHas('type', fn ($query) => $query->withTrashed()->where('is_paid', false))->get();
        foreach ($leaves as $leave) {
            $span = $leave->from_date->diffInDays($leave->to_date) + 1;
            $inside = $leave->from_date->copy()->max($start)->diffInDays($leave->to_date->copy()->min($end)) + 1;
            $leaveDays = round($leave->days * $inside / max(1, $span), 1);
            $amount = $money($leaveDays * $daily);
            $unpaidDays += $leaveDays;
            $unpaid += $amount;
            $items[] = ['kind' => 'deduction', 'source' => 'unpaid_leave', 'source_id' => $leave->id, 'label' => ($leave->type?->name ?? 'Unpaid leave') . ' · ' . $leave->datesText(), 'quantity' => $leaveDays, 'amount' => $amount];
        }

        // social insurance (employee share deducted, company share shown)
        $insuranceEmployee = 0.0;
        $insuranceCompany = 0.0;
        $insurance = EmployeeInsurance::where('employee_id', $staff->id)->first();
        $insured = $insurance && $insurance->is_insured && (!$insurance->start_date || $insurance->start_date->lte($end)) && (!$insurance->end_date || $insurance->end_date->gte($start));
        if ($insured) {
            if (!$insurance->insurance_no) {
                $warnings[] = 'Insured without an insurance number.';
            }
            if (!$settings->insuranceSet()) {
                $warnings[] = 'Insurance % not set (HR › Payroll Settings): no insurance deducted.';
            } elseif ($insurance->insured_wage && $gross > 0) {
                $insuranceEmployee = $money($insurance->insured_wage * $settings->insurance_employee_percent / 100);
                $insuranceCompany = $money($insurance->insured_wage * $settings->insurance_company_percent / 100);
                // never above the gross of the month
                if ($insuranceEmployee > $gross) {
                    $warnings[] = 'Insurance share ' . number_format($insuranceEmployee, 2) . ' limited to the gross ' . number_format($gross, 2) . '.';
                    $insuranceEmployee = $gross;
                }
                $items[] = ['kind' => 'deduction', 'source' => 'insurance', 'source_id' => $insurance->id, 'label' => 'Social insurance (' . rtrim(rtrim(number_format($settings->insurance_employee_percent, 2), '0'), '.') . '% of ' . number_format($insurance->insured_wage, 2) . ')', 'quantity' => null, 'amount' => $insuranceEmployee];
                $items[] = ['kind' => 'info', 'source' => 'insurance', 'source_id' => $insurance->id, 'label' => 'Company insurance share (' . rtrim(rtrim(number_format($settings->insurance_company_percent, 2), '0'), '.') . '%)', 'quantity' => null, 'amount' => $insuranceCompany];
            } elseif (!$insurance->insured_wage) {
                $warnings[] = 'Insured without an insured wage: no insurance deducted.';
            }
        }

        $tax = $money(max(0, $tax));
        if ($tax > 0) {
            $items[] = ['kind' => 'deduction', 'source' => 'tax', 'source_id' => 0, 'label' => 'Income tax', 'quantity' => null, 'amount' => $tax];
        }

        // advance installments planned for this month, lowered so the net stays ≥ 0
        $available = $money($gross + $bonuses - $deductions - $unpaid - $insuranceEmployee - $tax);
        $installments = 0.0;
        $advances = EmployeeAdvance::with('moves')->where('employee_id', $staff->id)->where('status', 'open')->orderBy('id')->get();
        foreach ($advances as $advance) {
            $row = collect($this->advanceService->schedule($advance, $start))->first(fn ($row) => $row['month']->equalTo($start));
            if (!$row) {
                continue;
            }
            $planned = $row['planned'];
            $take = $money(max(0, min($planned, $available - $installments)));
            if ($take < $planned) {
                $warnings[] = 'Advance #' . $advance->advance_no . ': ' . number_format($take, 2) . ' of ' . number_format($planned, 2) . ' (the net would go below 0).';
            }
            if ($take > 0) {
                $installments += $take;
                $items[] = ['kind' => 'deduction', 'source' => 'advance', 'source_id' => $advance->id, 'label' => 'Advance #' . $advance->advance_no . ' installment', 'quantity' => null, 'amount' => $take];
            }
        }
        $installments = $money($installments);
        $net = $money($available - $installments);
        if ($net < 0) {
            $warnings[] = 'Net is negative (' . number_format($net, 2) . '): lower the deductions / tax before locking.';
        }

        $values = [
            'snapshot' => [
                'name' => $staff->name, 'name_ar' => $staff->name_ar, 'employee_no' => $staff->employee_no,
                'position' => $staff->position?->name, 'insurance_no' => $insurance?->insurance_no, 'no_salary' => $noSalary,
            ],
            'days_in_service' => $days, 'month_days' => $monthDays, 'basic' => $basic, 'allowances' => $allowances, 'gross' => $gross,
            'bonuses' => $money($bonuses), 'deductions' => $money($deductions), 'unpaid_leave_days' => $unpaidDays, 'unpaid_leave' => $money($unpaid),
            'advance_installment' => $installments, 'insurance_employee' => $insuranceEmployee, 'insurance_company' => $insuranceCompany,
            'income_tax' => $tax, 'net' => $net, 'warnings' => $warnings ?: null,
        ];
        return [$values, $items];
    }

    // a draft line: the typed income tax + notes, then that line recalculated
    public function updateLine(PayrollRun $run, $lineId, array $data, Model $user): PayrollLine
    {
        $this->assertDraft($run);
        $this->assertMonth($run->account_id, $run->period);
        $line = $this->findLine($run, $lineId);
        return DB::transaction(function () use ($run, $line, $data) {
            $staff = Staff::withTrashed()->find($line->employee_id);
            [$values, $items] = $this->compute($staff, $run->period->copy(), PayrollSetting::of($run->account_id), (float) ($data['income_tax'] ?? 0));
            $line->items()->delete();
            $line->update($values + ['notes' => trim((string) ($data['notes'] ?? '')) ?: null]);
            foreach ($items as $item) {
                $line->items()->create($item);
            }
            $this->refreshTotals($run);
            $run->save();
            return $line;
        });
    }

    // ------------------------------------------------------------------ lock / reopen

    public function lock(PayrollRun $run, Model $user): PayrollRun
    {
        $this->assertDraft($run);
        $this->assertMonth($run->account_id, $run->period);
        return DB::transaction(function () use ($run, $user) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->first();
            $this->assertDraft($run);
            $this->rebuild($run, $user, 'recalculated');
            $negative = $this->negativeLines($run);
            if ($negative->isNotEmpty()) {
                // the whole lock is rolled back (the recalculation too)
                throw ValidationException::withMessages(['run' => 'Lock refused: negative net for ' . $negative->map(fn ($line) => $line->name . ' (' . number_format($line->net, 2) . ')')->implode(', ') . '.']);
            }
            foreach ($run->lines()->with('items')->get() as $line) {
                foreach ($line->items as $item) {
                    if ($item->source === 'adjustment') {
                        EmployeeAdjustment::whereKey($item->source_id)->where('payroll_line_id', 0)->update(['payroll_line_id' => $line->id]);
                    } elseif ($item->source === 'advance') {
                        $move = new EmployeeAdvanceMove(['account_id' => $run->account_id, 'advance_id' => $item->source_id, 'type' => 'deduct',
                            'amount' => $item->amount, 'period' => $run->period->toDateString(), 'reason' => 'Payroll ' . $run->period->format('F Y')]);
                        $move->payroll_line_id = $line->id;
                        $move->creatable()->associate($user);
                        $move->save();
                        $this->syncAdvance($item->source_id);
                    }
                }
            }
            $run->status = 'locked';
            $run->locked_at = now();
            $run->locked_by = $user->getKey();
            $run->history = array_merge($run->history ?? [], [['type' => 'locked', 'at' => now()->toDateTimeString(), 'by' => $this->who($user), 'totals' => $run->totals]]);
            $run->save();
            return $run;
        });
    }

    public function reopenError(PayrollRun $run): ?string
    {
        if (!$run->isLocked()) {
            return 'This payroll is not locked.';
        }
        if (PayrollRun::where('account_id', $run->account_id)->where('status', 'locked')->whereDate('period', '>', $run->period)->exists()) {
            return 'Only the last locked month can be reopened.';
        }
        if ($run->payments()->whereNull('cancelled_at')->exists()) {
            return 'Salaries were paid from it: undo the payments first.';
        }
        return null;
    }

    public function reopen(PayrollRun $run, string $reason, Model $user): PayrollRun
    {
        if ($error = $this->reopenError($run)) {
            throw ValidationException::withMessages(['reopen_reason' => $error]);
        }
        return DB::transaction(function () use ($run, $reason, $user) {
            $lineIds = $run->lines()->pluck('id');
            EmployeeAdjustment::whereIn('payroll_line_id', $lineIds)->update(['payroll_line_id' => 0]);
            foreach (EmployeeAdvanceMove::where('type', 'deduct')->whereIn('payroll_line_id', $lineIds)->get() as $move) {
                $move->update(['delete_reason' => 'Payroll ' . $run->period->format('F Y') . ' reopened: ' . $reason, 'deleted_by' => $user->getKey()]);
                $move->delete();
                $this->syncAdvance($move->advance_id);
            }
            $run->status = 'draft';
            $run->locked_at = null;
            $run->locked_by = 0;
            $run->reopen_count++;
            $run->history = array_merge($run->history ?? [], [['type' => 'reopened', 'at' => now()->toDateTimeString(), 'by' => $this->who($user), 'reason' => $reason]]);
            $run->save();
            return $run;
        });
    }

    // ------------------------------------------------------------------ pay

    // $data: amount, payment_method_id, paid_on
    public function pay(PayrollRun $run, $lineId, array $data, Model $user): PayrollPayment
    {
        $this->assertPayable($run);
        $line = $this->findLine($run, $lineId);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0 || $amount > $line->remaining + 0.001) {
            throw ValidationException::withMessages(['amount' => 'Between 0.01 and the remaining ' . number_format($line->remaining, 2) . '.']);
        }
        return DB::transaction(fn () => $this->payLine($run, $line, $amount, $data, $user));
    }

    // every line with a remaining, one method + date
    public function payAll(PayrollRun $run, array $data, Model $user): int
    {
        $this->assertPayable($run);
        return DB::transaction(function () use ($run, $data, $user) {
            $count = 0;
            foreach ($run->lines()->get() as $line) {
                if ($line->remaining > 0.004) {
                    $this->payLine($run, $line, $line->remaining, $data, $user);
                    $count++;
                }
            }
            return $count;
        });
    }

    public function undoPayment(PayrollRun $run, $paymentId, string $reason, Model $user): PayrollPayment
    {
        $payment = PayrollPayment::where('run_id', $run->id)->whereNull('cancelled_at')->findOrFail($paymentId);
        return DB::transaction(function () use ($run, $payment, $reason, $user) {
            $payment->update(['cancelled_at' => now(), 'cancelled_by' => $user->getKey(), 'cancel_reason' => $reason]);
            if ($payment->expense && !$payment->expense->trashed()) {
                $this->expenseService->voidCompany($payment->expense, 'Salary payment undone: ' . $reason, $user);
            }
            $this->refreshPaid($payment->line);
            $this->refreshTotals($run);
            $run->save();
            $this->staffEvent($payment->line, 'salary_payment_undone', ['month' => $run->period->format('F Y'), 'amount' => [number_format($payment->amount, 2), null]], $reason, $user);
            return $payment;
        });
    }

    // ------------------------------------------------------------------ payslip

    public function payslipData(PayrollRun $run, PayrollLine $line, string $lang): array
    {
        $account = \Modules\AccountModule\app\Models\Account::find($run->account_id);
        return ['run' => $run, 'line' => $line->loadMissing('items'), 'account' => $account, 'lang' => $lang];
    }

    // ------------------------------------------------------------------ helpers

    private function payLine(PayrollRun $run, PayrollLine $line, float $amount, array $data, Model $user): PayrollPayment
    {
        $methodId = (int) ($data['payment_method_id'] ?? 0);
        if (!PaymentMethod::where('account_id', $run->account_id)->where('is_active', true)->whereKey($methodId)->exists()) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose how the salary is paid.']);
        }
        $paidOn = Carbon::parse($data['paid_on'] ?? today())->toDateString();
        $expense = $this->expenseService->createCompany($run->account_id, 'salaries', [
            'item' => 'Salary ' . $run->period->format('m-Y') . ' · ' . $line->name, 'amount' => $amount,
            'payment_method_id' => $methodId, 'spent_at' => $paidOn,
        ], $user);
        $payment = new PayrollPayment(['account_id' => $run->account_id, 'run_id' => $run->id, 'line_id' => $line->id, 'employee_id' => $line->employee_id,
            'amount' => $amount, 'payment_method_id' => $methodId, 'paid_on' => $paidOn, 'expense_id' => $expense->id]);
        $payment->creatable()->associate($user);
        $payment->save();
        $this->refreshPaid($line);
        $this->refreshTotals($run);
        $run->save();
        $this->staffEvent($line, 'salary_paid', ['month' => $run->period->format('F Y'), 'amount' => [null, number_format($amount, 2)], 'cash out' => [null, '#' . $expense->expense_no . ' (company)']], null, $user);
        return $payment;
    }

    private function refreshPaid(PayrollLine $line): void
    {
        $line->update(['paid_amount' => round((float) $line->payments()->whereNull('cancelled_at')->sum('amount'), 2)]);
    }

    private function refreshTotals(PayrollRun $run): void
    {
        // lines with no salary for the month are out of the totals (warned)
        $lines = $run->lines()->get()->reject(fn ($line) => !empty($line->snapshot['no_salary']));
        $totals = ['count' => $lines->count()];
        foreach (PayrollLine::MONEY as $field) {
            $totals[$field] = round((float) $lines->sum($field), 2);
        }
        $run->totals = $totals;
    }

    // remaining 0 → settled; above 0 → open (a cancelled one stays)
    private function syncAdvance($advanceId): void
    {
        $advance = EmployeeAdvance::with('moves')->find($advanceId);
        if (!$advance || $advance->status === 'cancelled') {
            return;
        }
        $status = $this->advanceService->remaining($advance) <= 0 ? 'settled' : 'open';
        if ($status !== $advance->status) {
            $advance->update(['status' => $status]);
        }
    }

    private function assertDraft(?PayrollRun $run): void
    {
        if (!$run || $run->isLocked()) {
            throw ValidationException::withMessages(['run' => 'This payroll is locked.']);
        }
    }

    private function assertPayable(PayrollRun $run): void
    {
        $this->assertMonth($run->account_id, $run->period, 'amount');
        if (!$run->isLocked()) {
            throw ValidationException::withMessages(['amount' => 'Lock the payroll first.']);
        }
        if (!$this->canPay($run->account_id)) {
            throw ValidationException::withMessages(['amount' => 'Paying from payroll is not switched on yet: the "Salaries" Cash Out category must be made the system category first (docs/hr-plan.md §6).']);
        }
    }

    private function staffEvent(PayrollLine $line, string $type, array $changes, ?string $reason, Model $user): void
    {
        $event = new EmployeeEvent(['account_id' => $line->account_id, 'employee_id' => $line->employee_id, 'type' => $type, 'changes' => $changes, 'reason' => $reason]);
        $event->creatable()->associate($user);
        $event->save();
    }

    private function who(Model $user): string
    {
        return $user->userable?->displayName() ?? 'User #' . $user->getKey();
    }
}
