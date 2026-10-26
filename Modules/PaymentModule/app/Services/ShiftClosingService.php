<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\PaymentModule\app\Models\ShiftClosing;
use Modules\PaymentModule\app\Models\ShiftEvent;
use Modules\UserModule\app\Models\User;

/**
 * Shift closing, the ONE closing level (docs/payments-plan.md §2b, simplified 2026-10-13): the payments are linked to a closing
 * (payments.shift_closing_id), not taken by a time window (paid_at is a date): a login's OPEN SHIFT = their payments with
 * shift_closing_id 0. Every login collects into its own shift: the employees AND the account itself (no reminders for the account).
 * - the collector closes it at any time (a shift over midnight is one closing); the account can close an employee's on their behalf
 * - a closed shift keeps its payments; only the account edits / deletes one of them (reason required): paymentChanged()
 *   recalculates the totals, logs the change (before / after) and marks the shift "Edited after closing"; a refund of one
 *   of them goes into the refunder's open shift, the closed shift only gets the log entry + the mark
 * - expenses (2026-10-14) join the recorder's open shift too: the totals per method = payments - refunds - expenses = net to hand
 *   over; an expense changed after closing is logged the same way (expense_edit / expense_delete)
 * - every close, payment / expense change and reminder answer is logged (shift_events)
 * - closing sends the highest payment id the collector saw: a payment added meanwhile → refused (review again)
 * - reminders (employees only): config paymentmodule.shift_reminder_hours / shift_snooze_hours
 */
class ShiftClosingService
{
    const IMPORTED_NOTE = 'Imported from the old system';

    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    // an employee or an account login: both collect into their own shift
    public function isCollector(?Model $user): bool
    {
        return $user?->userable instanceof Employee || $user?->userable instanceof Account;
    }

    // an employee login (the reminders, still working answers and logout popup are for employees only)
    public function isEmployee(?Model $user): bool
    {
        return $user?->userable instanceof Employee;
    }

    // the users.id of the account's employees (a subquery)
    public function employeeUserIds($accountId)
    {
        return User::where('userable_type', (new Employee)->getMorphClass())
            ->whereIn('userable_id', Employee::withTrashed()->where('account_id', $accountId)->select('id'))
            ->select('id');
    }

    // the users.id of every login that collects for the account: its employees + the account itself (a subquery)
    public function collectorUserIds($accountId)
    {
        return User::where(fn ($query) => $query
            ->where(fn ($query) => $query->where('userable_type', (new Employee)->getMorphClass())
                ->whereIn('userable_id', Employee::withTrashed()->where('account_id', $accountId)->select('id')))
            ->orWhere(fn ($query) => $query->where('userable_type', (new Account)->getMorphClass())->where('userable_id', $accountId)))
            ->select('id');
    }

    // the payments of a login (not deleted)
    private function paymentsOf($accountId, $userId): Builder
    {
        return Payment::where('account_id', $accountId)
            ->where('creatable_type', (new User)->getMorphClass())->where('creatable_id', $userId);
    }

    // the collector's open shift: their payments not in a closing yet
    public function openPayments($accountId, $userId): Builder
    {
        return $this->paymentsOf($accountId, $userId)->where('shift_closing_id', 0);
    }

    // the collector's open shift: the expenses they recorded, not in a closing yet
    public function openExpenses($accountId, $userId): Builder
    {
        return Expense::where('account_id', $accountId)->where('shift_closing_id', 0)
            ->where('creatable_type', (new User)->getMorphClass())->where('creatable_id', $userId);
    }

    // created_at of the oldest unclosed payment / expense of a login (null = nothing open)
    private function openSince($accountId, $userId): ?Carbon
    {
        $since = collect([$this->openPayments($accountId, $userId)->min('created_at'), $this->openExpenses($accountId, $userId)->min('created_at')])
            ->filter()->min();
        return $since ? Carbon::parse($since) : null;
    }

    /**
     * The collector's open shift: ['payments' (oldest first), 'expenses', 'summary', 'since' (created_at of the oldest payment /
     * expense, null = none), 'last_closing' (their last closing, null = never)]
     */
    public function openPeriod($accountId, $userId): array
    {
        $payments = $this->openPayments($accountId, $userId)
            ->with('payable', 'member', 'paymentMethod', 'incomeCategory')->orderBy('created_at')->orderBy('id')->get();
        $expenses = $this->openExpenses($accountId, $userId)->with('expenseCategory', 'paymentMethod')->orderBy('created_at')->orderBy('id')->get();

        return [
            'payments' => $payments,
            'expenses' => $expenses,
            'summary' => $this->summary($accountId, $payments, $expenses),
            'since' => collect([$payments->first()?->created_at, $expenses->first()?->created_at])->filter()->min(),
            'last_closing' => ShiftClosing::where('account_id', $accountId)->where('user_id', $userId)->latest('closed_at')->first(),
        ];
    }

    /**
     * The open shift in numbers only (the Schedule page strip; two aggregate queries, no rows): null when nothing is open, else
     * ['since', 'cash_in' (payments), 'cash_out' (refunds + expenses)] — withholding apart (not cash), as in summary()
     */
    public function openTotals($accountId, $userId): ?array
    {
        // payments + expenses in ONE query (two one-row subqueries)
        $payments = $this->openPayments($accountId, $userId)->toBase()
            ->selectRaw("MIN(created_at) p_since, COALESCE(SUM(CASE WHEN type = 'payment' THEN amount END), 0) cash_in, COALESCE(SUM(CASE WHEN type = 'refund' THEN amount END), 0) refunds");
        $expenses = $this->openExpenses($accountId, $userId)->toBase()->selectRaw('MIN(created_at) e_since, COALESCE(SUM(amount), 0) total');
        $row = DB::query()->fromSub($payments, 'p')->crossJoinSub($expenses, 'e')->first();
        $since = collect([$row->p_since, $row->e_since])->filter()->min();

        return $since ? [
            'since' => Carbon::parse($since),
            'cash_in' => round((float) $row->cash_in, 2),
            'cash_out' => round((float) $row->refunds + (float) $row->total, 2),
        ] : null;
    }

    /**
     * The totals of some payments + expenses (an open shift / a closing; saved on the closing):
     * ['methods' => [method_id => ['name', 'payments', 'refunds', 'expenses', 'net' (= payments - refunds - expenses: to hand over)]],
     *  'refunds' / 'expenses' => ['count', 'total'] (all methods, their own minus lines), 'withholding' => ['count', 'total'],
     *  'no_method_expenses' (imported old method 5: in the expenses total, in no method's cash),
     *  'cash_total' (payments - refunds, withholding apart), 'expenses_total', 'net_total' (the methods' net), 'count' (payments),
     *  'max_id' / 'max_expense_id' (the newest shown), 'first_payment_at', 'last_payment_at' (the span, payments + expenses)]
     */
    public function summary($accountId, Collection $payments, ?Collection $expenses = null): array
    {
        $expenses ??= collect();
        $methodNames = PaymentMethod::withTrashed()->where('account_id', $accountId)->get()->pluck('name', 'id');
        $times = $payments->pluck('created_at')->merge($expenses->pluck('created_at'))->filter();
        $summary = ['methods' => [], 'refunds' => ['count' => 0, 'total' => 0.0], 'expenses' => ['count' => 0, 'total' => 0.0],
            'withholding' => ['count' => 0, 'total' => 0.0], 'no_method_expenses' => 0.0,
            'cash_total' => 0.0, 'expenses_total' => 0.0, 'net_total' => 0.0, 'count' => 0,
            'max_id' => (int) $payments->max('id'), 'max_expense_id' => (int) $expenses->max('id'),
            'first_payment_at' => $times->min()?->format('Y-m-d H:i:s'),
            'last_payment_at' => $times->max()?->format('Y-m-d H:i:s')];
        $line = fn ($methodId) => ['name' => $methodNames[$methodId] ?? 'Method #' . $methodId, 'payments' => 0.0, 'refunds' => 0.0, 'expenses' => 0.0, 'net' => 0.0];

        foreach ($payments as $payment) {
            $summary['count']++;
            if ($payment->type === 'withholding') {
                $summary['withholding']['count']++;
                $summary['withholding']['total'] = round($summary['withholding']['total'] + $payment->amount, 2);
                continue;
            }

            $methodId = (int) $payment->payment_method_id;
            $summary['methods'][$methodId] ??= $line($methodId);
            $method = &$summary['methods'][$methodId];
            $key = $payment->type === 'refund' ? 'refunds' : 'payments';
            $method[$key] = round($method[$key] + $payment->amount, 2);
            $method['net'] = round($method['net'] + $payment->signed_amount, 2);
            if ($payment->type === 'refund') {
                $summary['refunds']['count']++;
                $summary['refunds']['total'] = round($summary['refunds']['total'] + $payment->amount, 2);
            }
            $summary['cash_total'] = round($summary['cash_total'] + $payment->signed_amount, 2);
            unset($method);
        }

        foreach ($expenses as $expense) {
            $summary['expenses']['count']++;
            $summary['expenses']['total'] = round($summary['expenses']['total'] + $expense->amount, 2);
            $methodId = (int) $expense->payment_method_id;
            if (!$methodId) {
                $summary['no_method_expenses'] = round($summary['no_method_expenses'] + $expense->amount, 2);
                continue;
            }
            $summary['methods'][$methodId] ??= $line($methodId);
            $summary['methods'][$methodId]['expenses'] = round($summary['methods'][$methodId]['expenses'] + $expense->amount, 2);
            $summary['methods'][$methodId]['net'] = round($summary['methods'][$methodId]['net'] - $expense->amount, 2);
        }
        $summary['expenses_total'] = $summary['expenses']['total'];
        $summary['net_total'] = round(collect($summary['methods'])->sum('net'), 2);

        return $summary;
    }

    /**
     * Close a collector's open shift. $upToId / $upToExpenseId = the highest payment / expense id shown in the confirmation
     * (a newer one = refused). $collector: an employee or the account's own login; $by: the collector (close / logout_closed)
     * or the account (close_on_behalf).
     */
    public function close($accountId, User $collector, int $upToId, Model $by, string $action = 'close', int $upToExpenseId = 0): ShiftClosing
    {
        $this->assertCollectorOf($accountId, $collector);
        $onBehalf = $by->getKey() !== $collector->getKey();
        if ($onBehalf && !$this->paymentService->isOwner($by)) {
            abort(403, 'Only the account can close another employee\'s shift.');
        }
        $action = $onBehalf ? 'close_on_behalf' : (in_array($action, ['close', 'logout_closed'], true) ? $action : 'close');

        return DB::transaction(function () use ($accountId, $collector, $upToId, $upToExpenseId, $by, $action) {
            // locked until the end: a payment / expense can't join / leave the shift while it is closed
            $payments = $this->openPayments($accountId, $collector->getKey())->lockForUpdate()->get();
            $expenses = $this->openExpenses($accountId, $collector->getKey())->lockForUpdate()->get();
            if ($payments->isEmpty() && $expenses->isEmpty()) {
                throw ValidationException::withMessages(['shift' => 'There is no cash in or cash out to close.']);
            }
            if ((int) $payments->max('id') !== $upToId || (int) $expenses->max('id') !== $upToExpenseId) {
                throw ValidationException::withMessages(['shift' => 'Cash in or cash out was added or changed since you opened this window. Review the totals again.']);
            }

            $closing = $this->saveTotals(new ShiftClosing([
                'account_id' => $accountId,
                'user_id' => $collector->getKey(),
                'closed_by' => $by->getKey(),
                'closed_at' => now(),
            ]), $accountId, $payments, $expenses);
            Payment::whereKey($payments->modelKeys())->update(['shift_closing_id' => $closing->id]);
            Expense::whereKey($expenses->modelKeys())->update(['shift_closing_id' => $closing->id]);
            $this->log($closing, $action, $by);

            return $closing;
        });
    }

    /**
     * Reopen a closing (the account only, reason required; user 2026-10-26): its payments / expenses go back to the login's open
     * shift (shift_closing_id 0, closed again from My Shift / on behalf), the closing row is deleted, and a "reopen" event keeps
     * its id, totals, who and why (its other events stay). Only the login's LATEST closing (an older one would put old money
     * under a newer closing), never an imported one (years of old payments).
     */
    public function reopen($accountId, $id, Model $by, string $reason): ShiftClosing
    {
        if (!$this->paymentService->isOwner($by)) {
            abort(403, 'Only the account can reopen a shift.');
        }

        return DB::transaction(function () use ($accountId, $id, $by, $reason) {
            $closing = ShiftClosing::where('account_id', $accountId)->lockForUpdate()->findOrFail($id);
            if ($error = $this->reopenError($closing)) {
                throw ValidationException::withMessages(['reason' => $error]);
            }
            Payment::where('shift_closing_id', $closing->id)->update(['shift_closing_id' => 0]);
            Expense::where('shift_closing_id', $closing->id)->update(['shift_closing_id' => 0]);
            $this->log($closing, 'reopen', $by, $reason);
            $closing->delete();

            return $closing;
        });
    }

    // why a closing can't be reopened (null = it can): imported, or not the login's latest closing
    public function reopenError(ShiftClosing $closing): ?string
    {
        if ($closing->is_imported) {
            return 'An imported shift (from the old system) can\'t be reopened.';
        }
        $newer = ShiftClosing::where('account_id', $closing->account_id)->where('user_id', $closing->user_id)
            ->where(fn ($query) => $query->where('closed_at', '>', $closing->closed_at)
                ->orWhere(fn ($query) => $query->where('closed_at', $closing->closed_at)->where('id', '>', $closing->id)))
            ->exists();

        return $newer ? 'Only the last closing of this login can be reopened: reopen the newer one first.' : null;
    }

    // a reminder answer of the employee: still_working (reason required) | logout_still_working
    public function stillWorking($accountId, User $employee, string $action, ?string $reason = null): ShiftEvent
    {
        $this->assertEmployeeOf($accountId, $employee);
        if (!in_array($action, ['still_working', 'logout_still_working'], true)) {
            abort(404);
        }
        if ($action === 'still_working' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Write why you are still working (e.g. covering a colleague\'s shift).']);
        }

        return ShiftEvent::create([
            'account_id' => $accountId,
            'user_id' => $employee->getKey(),
            'shift_closing_id' => 0,
            'by_user_id' => $employee->getKey(),
            'action' => $action,
            'reason' => trim((string) $reason) ?: null,
        ]);
    }

    /**
     * The "close your shift" popup is due (employees only): the oldest unclosed payment is older than
     * config paymentmodule.shift_reminder_hours, and no "still working" answer in the last shift_snooze_hours. No payments → never.
     */
    public function reminderDue(Account $account, User $employee): bool
    {
        $since = $this->openSince($account->id, $employee->getKey());
        if (!$since || $since->gt(now()->subHours(self::reminderHours()))) {
            return false;
        }

        return !ShiftEvent::where('account_id', $account->id)->where('user_id', $employee->getKey())
            ->where('action', 'still_working')->where('created_at', '>', now()->subHours(self::snoozeHours()))->exists();
    }

    // the reminder hours (config): after this the "close your shift" popup; then again every snoozeHours() after "still working"
    public static function reminderHours(): int
    {
        return (int) config('paymentmodule.shift_reminder_hours', 12) ?: 12;
    }

    public static function snoozeHours(): int
    {
        return (int) config('paymentmodule.shift_snooze_hours', 4) ?: 4;
    }

    /**
     * The open shifts of the account's collectors, the account included (all, or the ones with payments dated $date):
     * [user_id => ['user', 'since', 'count', 'cash_total' (payments − refunds), 'refunds_total', 'expenses_total', 'net_total',
     * 'withholding_total', 'dated_count' (payments of $date)]], oldest first
     */
    public function openPeriods($accountId, $date = null): Collection
    {
        $day = $date ? Carbon::parse($date)->toDateString() : null;
        $payments = Payment::where('account_id', $accountId)->where('shift_closing_id', 0)
            ->where('creatable_type', (new User)->getMorphClass())->whereIn('creatable_id', $this->collectorUserIds($accountId))
            ->groupBy('creatable_id')
            ->selectRaw("creatable_id, MIN(created_at) since, COUNT(*) n,
                SUM(CASE type WHEN 'payment' THEN amount WHEN 'refund' THEN -amount ELSE 0 END) cash,
                SUM(IF(type = 'refund', amount, 0)) refunds, SUM(IF(type = 'withholding', amount, 0)) wh, SUM(paid_at = ?) dated", [$day])
            ->get()->keyBy('creatable_id');
        $expenses = Expense::where('account_id', $accountId)->where('shift_closing_id', 0)
            ->where('creatable_type', (new User)->getMorphClass())->whereIn('creatable_id', $this->collectorUserIds($accountId))
            ->groupBy('creatable_id')
            ->selectRaw('creatable_id, MIN(created_at) since, COUNT(*) n, SUM(amount) total, SUM(spent_at = ?) dated', [$day])
            ->get()->keyBy('creatable_id');
        $users = User::with('userable')->whereIn('id', $payments->keys()->merge($expenses->keys())->unique())->get()->keyBy('id');

        return $payments->keys()->merge($expenses->keys())->unique()
            ->map(function ($userId) use ($payments, $expenses, $users) {
                $payment = $payments[$userId] ?? null;
                $expense = $expenses[$userId] ?? null;
                return [
                    'user_id' => (int) $userId,
                    'user' => $users[$userId] ?? null,
                    'since' => Carbon::parse(collect([$payment?->since, $expense?->since])->filter()->min()),
                    'count' => (int) ($payment?->n ?? 0),
                    'expenses_count' => (int) ($expense?->n ?? 0),
                    'cash_total' => round((float) ($payment?->cash ?? 0), 2),
                    'refunds_total' => round((float) ($payment?->refunds ?? 0), 2),
                    'expenses_total' => round((float) ($expense?->total ?? 0), 2),
                    'net_total' => round((float) ($payment?->cash ?? 0) - (float) ($expense?->total ?? 0), 2),
                    'withholding_total' => round((float) ($payment?->wh ?? 0), 2),
                    'dated_count' => (int) ($payment?->dated ?? 0) + (int) ($expense?->dated ?? 0),
                ];
            })
            ->when($day, fn ($rows) => $rows->filter(fn ($row) => $row['dated_count'] > 0))
            ->sortBy('since')
            ->mapWithKeys(fn ($row) => [$row['user_id'] => $row]);
    }

    /**
     * The Daily Summary flags: employees' open shifts older than reminderHours() (oldest first; the account's own has no
     * reminders), each with 'answers' = the "still working" answers since it opened (popup + logout, newest first, with their reasons).
     */
    public function longOpenPeriods(Account $account): Collection
    {
        $limit = now()->subHours(self::reminderHours());
        $periods = $this->openPeriods($account->id)
            ->filter(fn ($period) => $period['since']->lt($limit) && $this->isEmployee($period['user']));
        $answers = ShiftEvent::where('account_id', $account->id)->where('shift_closing_id', 0)
            ->whereIn('action', ['still_working', 'logout_still_working'])->whereIn('user_id', $periods->keys())
            ->latest('id')->get()->groupBy('user_id');

        return $periods->map(fn ($period, $userId) => $period + [
            'answers' => ($answers[$userId] ?? collect())->filter(fn ($event) => $event->created_at->gte($period['since']))->values(),
        ]);
    }

    // the closings list (paged / ordered by DataTables); $userId: one collector's closings
    public function listQuery($accountId, $userId = null)
    {
        return ShiftClosing::with('user.userable', 'closedBy.userable')->where('account_id', $accountId)
            ->when($userId, fn ($query) => $query->where('user_id', $userId));
    }

    // 404 when it belongs to another account (or, with $userId, to another employee)
    public function findOne($accountId, $id, $userId = null): ShiftClosing
    {
        return $this->listQuery($accountId, $userId)->findOrFail($id);
    }

    // a closed shift's totals from its payments + expenses now (shifts:recalculate, after an import); no log, no "edited" mark
    public function recalculate(ShiftClosing $closing): ShiftClosing
    {
        return DB::transaction(fn () => $this->saveTotals($closing, $closing->account_id,
            $closing->payments()->lockForUpdate()->get(), $closing->expenses()->lockForUpdate()->get()));
    }

    // the shift popup's payments (Payments list cells): the newest POPUP_LIMIT first (an imported shift holds thousands)
    const POPUP_LIMIT = 200;

    public function popupPayments(ShiftClosing $closing): Collection
    {
        return $closing->payments()->with('member', 'paymentMethod', 'incomeCategory')
            ->with(['payable' => fn ($morph) => $morph->morphWith([\Modules\ReservationModule\app\Models\Reservation::class => ['space', 'unit.color', 'plan']])])
            ->latest('created_at')->latest('id')->limit(self::POPUP_LIMIT)->get();
    }

    // the shift popup's expenses: the newest POPUP_LIMIT first
    public function popupExpenses(ShiftClosing $closing): Collection
    {
        return $closing->expenses()->with('expenseCategory', 'paymentMethod')->latest('created_at')->latest('id')->limit(self::POPUP_LIMIT)->get();
    }

    // the closings with payments / expenses dated $date (the Daily Summary)
    public function closingsForDate($accountId, $date): Collection
    {
        $date = Carbon::parse($date)->toDateString();
        return $this->listQuery($accountId)
            ->where(fn ($query) => $query->whereHas('payments', fn ($query) => $query->where('paid_at', $date))
                ->orWhereHas('expenses', fn ($query) => $query->where('spent_at', $date)))
            ->orderBy('closed_at')->get();
    }

    // the totals of a closing from its payments, saved
    private function saveTotals(ShiftClosing $closing, $accountId, Collection $payments, ?Collection $expenses = null): ShiftClosing
    {
        $summary = $this->summary($accountId, $payments, $expenses);
        $closing->fill([
            'first_payment_at' => $summary['first_payment_at'],
            'last_payment_at' => $summary['last_payment_at'],
            'count' => $summary['count'],
            'cash_total' => $summary['cash_total'],
            'withholding_total' => $summary['withholding']['total'],
            'expenses_total' => $summary['expenses_total'],
            'totals' => $summary,
        ])->save();

        return $closing;
    }

    /**
     * A payment / expense of a CLOSED shift was changed by the account (PaymentService / ExpenseService update / delete), or a
     * payment refunded by anyone: payment_edit / payment_delete / expense_edit / expense_delete → the totals recalculated from its
     * payments + expenses now; payment_refund → totals unchanged (the refund row is in the refunder's open shift). Always: the log entry (before / after, who, reason) + "Edited after closing".
     * Call inside the change's transaction.
     */
    public function paymentChanged(ShiftClosing $closing, string $action, Payment|Expense $record, Model $by, string $reason, array $changes): void
    {
        if (!in_array($action, ['payment_edit', 'payment_delete', 'payment_refund', 'expense_edit', 'expense_delete'], true)) {
            abort(500, 'Unknown shift change.');
        }
        if ($action !== 'payment_refund') {
            $this->saveTotals($closing, $closing->account_id, $closing->payments()->lockForUpdate()->get(), $closing->expenses()->lockForUpdate()->get());
        }
        $closing->forceFill(['edited_at' => now(), 'edit_count' => $closing->edit_count + 1])->save();
        $record instanceof Expense
            ? $this->log($closing, $action, $by, $reason, 0, $changes, $record->getKey())
            : $this->log($closing, $action, $by, $reason, $record->getKey(), $changes);
    }

    private function log(ShiftClosing $closing, string $action, Model $by, ?string $reason = null, int $paymentId = 0, ?array $changes = null, int $expenseId = 0): void
    {
        ShiftEvent::create([
            'account_id' => $closing->account_id,
            'user_id' => $closing->user_id,
            'shift_closing_id' => $closing->id,
            'payment_id' => $paymentId,
            'expense_id' => $expenseId,
            'by_user_id' => $by->getKey(),
            'action' => $action,
            'reason' => $reason,
            'totals' => $closing->totals,
            'changes' => $changes,
        ]);
    }

    // a login that collects for this account: one of its employees, or the account itself (404 otherwise)
    private function assertCollectorOf($accountId, User $user): void
    {
        if ($user->userable instanceof Account && (int) $user->userable->getKey() === (int) $accountId) {
            return;
        }
        $this->assertEmployeeOf($accountId, $user);
    }

    // an employee login of this account (404 otherwise)
    private function assertEmployeeOf($accountId, User $user): void
    {
        $employee = $user->userable;
        if (!$employee instanceof Employee || (int) $employee->account_id !== (int) $accountId) {
            abort(404);
        }
    }
}
