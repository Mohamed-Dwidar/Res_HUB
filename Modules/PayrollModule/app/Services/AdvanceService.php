<?php

namespace Modules\PayrollModule\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\HrModule\app\Models\Staff;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\PaymentModule\app\Services\ExpenseService;
use Modules\PaymentModule\app\Services\IncomeCategoryService;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\PayrollModule\app\Models\EmployeeAdvance;
use Modules\PayrollModule\app\Models\EmployeeAdvanceMove;
use Modules\PayrollModule\app\Services\Concerns\PayrollHelpers;

/**
 * Advances of a staff file (docs/hr-plan.md §3.5, H4).
 * - give(): the advance + its company Cash Out (ExpenseService::createCompany(), category "Advances", outside every shift).
 * - cancel(): reason required; voids the company Cash Out in the same step; refused once anything was deducted / repaid.
 * - repay(): cash = a Cash In "Advance repayment" (not income) in the RECORDER's open shift (user 2026-11-05: the cash is in
 *   their hands); change(): a new installment from a month. ("Extra deduction" was removed 2026-11-05: the move type 'extra'
 *   stays in the table, unused; old ones are cancelled and ignored.)
 * - cancelMove(): change (hr.salary); a cash repayment = the owner only (its Cash In is deleted, logged).
 * Remaining = amount − Σ deduct − Σ repay (moves not cancelled); remaining 0 = settled. Every change → employee_events.
 * Nothing is deducted until payroll (H5 reads schedule() of the month: the installment, never above the remaining).
 */
class AdvanceService
{
    use PayrollHelpers;

    public function __construct(private ExpenseService $expenseService, private PaymentService $paymentService)
    {
    }

    // the person's advances: open first, then newest
    public function list(Staff $staff): Collection
    {
        return EmployeeAdvance::with('moves.creatable.userable', 'moves.payment', 'paymentMethod', 'creatable.userable', 'expense')
            ->where('account_id', $staff->account_id)->where('employee_id', $staff->id)
            ->orderByRaw("status = 'open' DESC")->orderByDesc('id')->get();
    }

    public function find(Staff $staff, $id): EmployeeAdvance
    {
        return EmployeeAdvance::with('moves')->where('account_id', $staff->account_id)->where('employee_id', $staff->id)->findOrFail($id);
    }

    public function findMove(EmployeeAdvance $advance, $moveId): EmployeeAdvanceMove
    {
        return EmployeeAdvanceMove::where('advance_id', $advance->id)->findOrFail($moveId);
    }

    // the methods the money can be paid / received by: [id => name], active
    public function methodOptions($accountId): array
    {
        return app(\Modules\PaymentModule\app\Services\PaymentMethodService::class)->options($accountId);
    }

    public function remaining(EmployeeAdvance $advance): float
    {
        $moves = $advance->moves->whereNull('deleted_at');
        return round($advance->amount - $moves->whereIn('type', ['deduct', 'repay'])->sum('amount'), 2);
    }

    public function repaid(EmployeeAdvance $advance): float
    {
        return round($advance->amount - $this->remaining($advance), 2);
    }

    // the installment of a month: the latest "change" from a month ≤ it, else the advance's own
    public function installmentFor(EmployeeAdvance $advance, Carbon $month): float
    {
        $change = $advance->moves->whereNull('deleted_at')->where('type', 'change')
            ->filter(fn ($move) => $move->period && $move->period->lte($month))->sortBy([['period', 'desc'], ['id', 'desc']])->first();
        return $change ? (float) $change->amount : (float) $advance->installment_amount;
    }

    /**
     * The planned deductions of an open advance, from max(first month, this month | $from): [['month' => Carbon, 'installment',
     * 'planned' (never above what is left), 'left' (after it)]] until nothing is left (max 60 rows). H5 reads the month.
     */
    public function schedule(EmployeeAdvance $advance, ?Carbon $from = null): array
    {
        if ($advance->status !== 'open') {
            return [];
        }
        $left = $this->remaining($advance);
        // payroll asks from its own month ($from); the tab from this month
        $month = $advance->first_period->copy()->max(($from ?? Carbon::today())->copy()->startOfMonth());
        $rows = [];
        while ($left > 0.004 && count($rows) < 60) {
            $installment = $this->installmentFor($advance, $month);
            $planned = round(min($installment, $left), 2);
            if ($planned <= 0) {
                break; // an installment of 0 from a change: nothing more planned
            }
            $left = round($left - $planned, 2);
            $rows[] = ['month' => $month->copy(), 'installment' => $installment, 'planned' => $planned, 'left' => $left];
            $month->addMonth();
        }
        return $rows;
    }

    // ------------------------------------------------------------------ summary / warnings

    // [Y-m-01 => planned installments of the person's open advances that month]
    public function monthTotals(Collection $advances): array
    {
        $totals = [];
        foreach ($advances->where('status', 'open') as $advance) {
            foreach ($this->schedule($advance) as $row) {
                $key = $row['month']->toDateString();
                $totals[$key] = round(($totals[$key] ?? 0) + $row['planned'], 2);
            }
        }
        ksort($totals);
        return $totals;
    }

    /**
     * The tab's top line for the next payroll month (= this month): ['month', 'installments', 'bonus' / 'deduction' =>
     * ['amount', 'days']] + 'warnings' = the months whose installments go above advance_warn_percent of the salary in force
     * then (yellow, never a block). $adjustments = AdjustmentService::list() (cancelled ones skipped).
     */
    public function summary(Staff $staff, Collection $advances, Collection $adjustments): array
    {
        $month = Carbon::today()->startOfMonth();
        $totals = $this->monthTotals($advances);
        $sum = function (string $kind) use ($adjustments, $month) {
            $rows = $adjustments->whereNull('deleted_at')->filter(fn ($row) => $row->period->equalTo($month) && $row->type?->kind === $kind);
            return ['amount' => round((float) $rows->sum('amount'), 2), 'days' => round((float) $rows->sum('days'), 2)];
        };
        return [
            'month' => $month,
            'installments' => $totals[$month->toDateString()] ?? 0.0,
            'bonus' => $sum('bonus'),
            'deduction' => $sum('deduction'),
            'warnings' => $this->overLimit($staff, $totals),
        ];
    }

    // ["Dec 2026: installments 2,400.00 = 60% of the salary 4,000.00 (warn above 50%)"] — months above the setting's %
    public function overLimit(Staff $staff, array $totals): array
    {
        $percent = (float) (\Modules\PayrollModule\app\Models\PayrollSetting::of($staff->account_id)->advance_warn_percent ?? 50);
        $salaries = app(SalaryService::class);
        $history = $salaries->history($staff);
        $warnings = [];
        foreach ($totals as $key => $total) {
            $month = Carbon::parse($key);
            $salary = $salaries->current($history, $month->copy()->endOfMonth())?->total;
            if (!$salary) {
                $warnings[] = $month->format('M Y') . ': installments ' . $this->money($total) . ' — no salary to compare with.';
                break;
            }
            $share = $total / $salary * 100;
            if ($share > $percent + 0.001) {
                // whole % unless rounding would hide the excess (70.03% → "70.0%")
                $shown = round($share) > $percent ? (string) round($share) : number_format($share, 1);
                $warnings[] = $month->format('M Y') . ': installments ' . $this->money($total) . ' = ' . $shown . '% of the salary ' . $this->money($salary)
                    . ' (warn above ' . rtrim(rtrim(number_format($percent, 2), '0'), '.') . '%).';
            }
        }
        return $warnings;
    }

    // ------------------------------------------------------------------ give / cancel

    /**
     * $data: amount, given_on, installments (1–60), first_period (Y-m-01, not before the month given), payment_method_id, reason.
     * Installment = amount ÷ installments rounded UP to the cent (the last month takes what is left).
     */
    public function give(Staff $staff, array $data, Model $user): EmployeeAdvance
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Write the amount.']);
        }
        $count = (int) ($data['installments'] ?? 0);
        if ($count < 1 || $count > 60) {
            throw ValidationException::withMessages(['installments' => 'Between 1 and 60 months.']);
        }
        $givenOn = Carbon::parse($data['given_on'] ?? today())->startOfDay();
        $first = $this->month($data['first_period'] ?? null);
        if (!$first) {
            throw ValidationException::withMessages(['first_period' => 'Choose the first deduction month.']);
        }
        if ($first->lt($givenOn->copy()->startOfMonth())) {
            throw ValidationException::withMessages(['first_period' => 'The first deduction can\'t be before the month the advance is given.']);
        }
        if (!PaymentMethod::where('account_id', $staff->account_id)->where('is_active', true)->whereKey((int) ($data['payment_method_id'] ?? 0))->exists()) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose how the money was paid.']);
        }

        return DB::transaction(function () use ($staff, $data, $user, $amount, $count, $givenOn, $first) {
            $number = (int) EmployeeAdvance::where('account_id', $staff->account_id)->lockForUpdate()->max('advance_no') + 1;
            $advance = new EmployeeAdvance([
                'account_id' => $staff->account_id, 'employee_id' => $staff->id, 'advance_no' => $number, 'amount' => $amount,
                'given_on' => $givenOn->toDateString(), 'installments' => $count, 'installment_amount' => ceil($amount * 100 / $count) / 100,
                'first_period' => $first->toDateString(), 'payment_method_id' => (int) $data['payment_method_id'],
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null, 'status' => 'open',
            ]);
            $advance->creatable()->associate($user);
            $advance->save();
            // the money: an account-level company Cash Out (outside every shift, §6b)
            $expense = $this->expenseService->createCompany($staff->account_id, 'advances', [
                'item' => 'Advance #' . $number . ' · ' . $staff->name, 'amount' => $amount, 'payment_method_id' => (int) $data['payment_method_id'],
                'spent_at' => $givenOn->toDateString(), 'notes' => $advance->reason,
            ], $user);
            $advance->expense_id = $expense->id;
            $advance->save();
            $this->event($staff, 'advance_given', [
                'advance' => [null, '#' . $number], 'amount' => [null, $this->money($amount)],
                'installments' => [null, $count . ' × ' . $this->money($advance->installment_amount) . ' from ' . $first->format('F Y')],
                'cash out' => [null, '#' . $expense->expense_no . ' (company)'],
            ], $advance->reason, $user);
            return $advance;
        });
    }

    // a mistake: the advance + its company Cash Out voided together (who / when / reason)
    public function cancel(Staff $staff, $id, string $reason, Model $user): EmployeeAdvance
    {
        $advance = $this->find($staff, $id);
        if ($advance->status === 'cancelled') {
            throw ValidationException::withMessages(['cancel_reason' => 'This advance is already cancelled.']);
        }
        if ($advance->moves->whereNull('deleted_at')->whereIn('type', ['deduct', 'repay'])->isNotEmpty()) {
            throw ValidationException::withMessages(['cancel_reason' => 'Part of it was already deducted / repaid: it can\'t be cancelled. Change the installment or record a repayment instead.']);
        }
        return DB::transaction(function () use ($staff, $advance, $reason, $user) {
            $advance->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->getKey(), 'cancel_reason' => $reason]);
            $expense = $advance->expense;
            if ($expense && !$expense->trashed()) {
                $this->expenseService->voidCompany($expense, 'Advance #' . $advance->advance_no . ' cancelled: ' . $reason, $user);
            }
            $this->event($staff, 'advance_cancelled', [
                'advance' => '#' . $advance->advance_no, 'amount' => [$this->money($advance->amount), null],
                'cash out' => $expense ? ['#' . $expense->expense_no, 'voided'] : null,
            ], $reason, $user);
            return $advance;
        });
    }

    // ------------------------------------------------------------------ moves

    // cash back: $data amount, payment_method_id, paid_at (the owner; employees = the business date), reason
    public function repay(Staff $staff, $id, array $data, Model $user): EmployeeAdvanceMove
    {
        $advance = $this->openAdvance($staff, $id);
        $amount = $this->upToRemaining($advance, $data['amount'] ?? 0);
        $category = app(IncomeCategoryService::class)->generated($staff->account_id, 'advance_repayment');

        return DB::transaction(function () use ($staff, $advance, $amount, $category, $data, $user) {
            // a Cash In of the recorder's open shift (system category: locked in the Cash In screens)
            $payment = $this->paymentService->create($staff->account_id, [
                'income_category_id' => $category->id, 'type' => 'payment', 'amount' => $amount,
                'payment_method_id' => (int) ($data['payment_method_id'] ?? 0), 'paid_at' => $data['paid_at'] ?? null,
                'reference' => $data['reference'] ?? null, 'notes' => 'Advance #' . $advance->advance_no . ' repayment · ' . $staff->name,
            ], $user, true);
            $move = $this->addMove($advance, 'repay', $amount, $payment->paid_at->copy()->startOfMonth(), $data['reason'] ?? null, $user, $payment->id);
            $this->syncStatus($advance);
            $this->event($staff, 'advance_repaid', [
                'advance' => '#' . $advance->advance_no, 'amount' => [null, $this->money($amount)], 'cash in' => [null, '#' . $payment->payment_no],
                'remaining' => [null, $this->money($this->remaining($advance->fresh('moves')))],
            ], $move->reason, $user);
            return $move;
        });
    }

    // a new installment from a month (≥ this month): $data period, amount (0 = pause), reason
    public function change(Staff $staff, $id, array $data, Model $user): EmployeeAdvanceMove
    {
        $advance = $this->openAdvance($staff, $id);
        $amount = round((float) ($data['amount'] ?? -1), 2);
        if ($amount < 0) {
            throw ValidationException::withMessages(['amount' => 'Write the new monthly installment (0 = pause).']);
        }
        $period = $this->futureMonth($data['period'] ?? null);
        $before = $this->installmentFor($advance, $period);
        return DB::transaction(function () use ($staff, $advance, $amount, $period, $before, $data, $user) {
            $move = $this->addMove($advance, 'change', $amount, $period, $data['reason'] ?? null, $user);
            $this->event($staff, 'advance_changed', ['advance' => '#' . $advance->advance_no, 'installment from ' . $period->format('F Y') => [$this->money($before), $this->money($amount)]], $move->reason, $user);
            return $move;
        });
    }

    /**
     * Cancel a move (reason): an installment change; a cash repayment = the owner only (its Cash In is deleted the system way, logged
     * by PaymentService, the shift totals follow). A payroll deduction (H5) can't be cancelled here.
     */
    public function cancelMove(Staff $staff, $id, $moveId, string $reason, Model $user): EmployeeAdvanceMove
    {
        $advance = $this->find($staff, $id);
        $move = $this->findMove($advance, $moveId);
        if ($move->trashed()) {
            throw ValidationException::withMessages(['cancel_reason' => 'Already cancelled.']);
        }
        if ($move->type === 'deduct') {
            throw ValidationException::withMessages(['cancel_reason' => 'A payroll deduction is changed from the payroll.']);
        }
        if (!in_array($move->type, ['change', 'repay'], true)) {
            throw ValidationException::withMessages(['cancel_reason' => 'This move can\'t be cancelled.']);
        }
        if ($move->type === 'repay' && !$this->paymentService->isOwner($user)) {
            abort(403, 'Only the account owner can cancel a cash repayment (it deletes its Cash In).');
        }
        if ($advance->status === 'cancelled') {
            throw ValidationException::withMessages(['cancel_reason' => 'The advance is cancelled.']);
        }
        return DB::transaction(function () use ($staff, $advance, $move, $reason, $user) {
            $move->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
            $move->delete();
            if ($move->type === 'repay' && $move->payment_id && $move->payment && !$move->payment->trashed()) {
                $this->paymentService->delete($staff->account_id, $move->payment_id, 'Advance #' . $advance->advance_no . ' repayment cancelled: ' . $reason, $user, true);
            }
            $this->syncStatus($advance);
            $this->event($staff, 'advance_move_cancelled', [
                'advance' => '#' . $advance->advance_no, strtolower($move->type_label) => [$this->money($move->amount) . ($move->period ? ' · ' . $move->period->format('F Y') : ''), null],
            ], $reason, $user);
            return $move;
        });
    }

    // ------------------------------------------------------------------ helpers

    private function openAdvance(Staff $staff, $id): EmployeeAdvance
    {
        $advance = $this->find($staff, $id);
        if ($advance->status !== 'open') {
            throw ValidationException::withMessages(['amount' => 'This advance is ' . strtolower($advance->status_label) . '.']);
        }
        return $advance;
    }

    private function upToRemaining(EmployeeAdvance $advance, $value): float
    {
        $amount = round((float) $value, 2);
        $remaining = $this->remaining($advance);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Write the amount.']);
        }
        if ($amount > $remaining) {
            throw ValidationException::withMessages(['amount' => 'Not more than the remaining ' . $this->money($remaining) . '.']);
        }
        return $amount;
    }

    private function futureMonth($value): Carbon
    {
        $month = $this->month($value);
        if (!$month || $month->lt(Carbon::today()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Choose this month or a later one.']);
        }
        return $month;
    }

    private function addMove(EmployeeAdvance $advance, string $type, float $amount, ?Carbon $period, $reason, Model $user, int $paymentId = 0): EmployeeAdvanceMove
    {
        $move = new EmployeeAdvanceMove([
            'account_id' => $advance->account_id, 'advance_id' => $advance->id, 'type' => $type, 'amount' => $amount,
            'period' => $period?->toDateString(), 'payment_id' => $paymentId, 'reason' => trim((string) $reason) ?: null,
        ]);
        $move->creatable()->associate($user);
        $move->save();
        return $move;
    }

    // remaining 0 → settled; back above 0 → open (not a cancelled one)
    private function syncStatus(EmployeeAdvance $advance): void
    {
        $advance->load('moves');
        if ($advance->status === 'cancelled') {
            return;
        }
        $status = $this->remaining($advance) <= 0 ? 'settled' : 'open';
        if ($status !== $advance->status) {
            $advance->update(['status' => $status]);
        }
    }
}
