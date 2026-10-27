<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\ShiftEvent;
use Modules\UserModule\app\Models\User;

/**
 * Payment Exceptions (2026-10-27, fraud prevention; the account only, read only): in a date range (the RECORDING / change day),
 * optionally for one login:
 * - late: cash in / refunds / withholding / cash out recorded late (Concerns\LateEntry: after the end of their business date +
 *   Settings › General "late entry hours")
 * - edited: payment / expense edits and payment moves (shift_events payment_edit, expense_edit, payment_move; every edit is logged
 *   since 2026-10-27, before that only the edits of closed shifts)
 * - deleted: the deleted cash in / refunds / cash out (soft deleted: who, when, reason)
 * - expenses: cash outs recorded by EMPLOYEES from Settings › General "review cash out from" (default 500)
 * The user filter = the login who RECORDED the row (the one the exception is about); edits / deletes also show who changed it.
 */
class PaymentExceptionService
{
    const TABS = ['late' => 'Late Entries', 'edited' => 'Edited', 'deleted' => 'Deleted', 'expenses' => 'Expenses'];

    // the most rows one tab shows (the counts above are complete)
    const LIMIT = 500;

    // the logins that record money for the account: [user id => name], the account login first, then its employees by name
    public function users(int $accountId): array
    {
        $account = Account::find($accountId);
        $owner = $account?->user;
        $employees = User::where('userable_type', (new Employee)->getMorphClass())
            ->whereIn('userable_id', Employee::withTrashed()->where('account_id', $accountId)->pluck('id'))
            ->with('userable')->get()
            ->mapWithKeys(fn ($user) => [$user->id => $user->userable?->displayName() ?? $user->email])->sort();

        return ($owner ? [$owner->id => $account->displayName() . ' (account)'] : []) + $employees->all();
    }

    // per login: how many of each kind in the range, [user id => [late, edited, deleted, expenses]] (logins with none left out)
    public function counts(int $accountId, Carbon $from, Carbon $to): array
    {
        $counts = [];
        $add = function (string $kind, $rows) use (&$counts) {
            foreach ($rows as $userId => $n) {
                $counts[$userId][$kind] = ($counts[$userId][$kind] ?? 0) + $n;
            }
        };
        foreach ([Payment::class, Expense::class] as $model) {
            $add('late', $this->recorded($model::query()->lateEntries($accountId), $accountId, $from, $to)
                ->groupBy('creatable_id')->selectRaw('creatable_id, COUNT(*) AS n')->pluck('n', 'creatable_id'));
            $add('deleted', $this->deletedQuery($model, $accountId, $from, $to)
                ->groupBy('creatable_id')->selectRaw('creatable_id, COUNT(*) AS n')->pluck('n', 'creatable_id'));
        }
        $add('edited', $this->editsQuery($accountId, $from, $to)->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id'));
        $add('expenses', $this->bigExpensesQuery($accountId, $from, $to)
            ->groupBy('creatable_id')->selectRaw('creatable_id, COUNT(*) AS n')->pluck('n', 'creatable_id'));

        return $counts;
    }

    // the rows of one tab (newest first, at most LIMIT)
    public function rows(int $accountId, string $tab, Carbon $from, Carbon $to, ?int $userId): Collection
    {
        $forUser = fn ($query, $column = 'creatable_id') => $query->when($userId, fn ($query) => $query->where($column, $userId));

        return match ($tab) {
            'late' => $this->withRecorder($forUser($this->recorded(Payment::query()->lateEntries($accountId), $accountId, $from, $to))
                    ->with('member', 'paymentMethod')->latest()->limit(self::LIMIT)->get())
                ->concat($this->withRecorder($forUser($this->recorded(Expense::query()->lateEntries($accountId), $accountId, $from, $to))
                    ->with('expenseCategory', 'paymentMethod')->latest()->limit(self::LIMIT)->get()))
                ->sortByDesc('created_at')->take(self::LIMIT)->values(),
            'edited' => $forUser($this->editsQuery($accountId, $from, $to), 'user_id')
                ->with('user.userable', 'byUser.userable')->latest()->limit(self::LIMIT)->get()
                ->each(function ($event) {
                    $event->record = $event->payment_id ? Payment::withTrashed()->find($event->payment_id) : Expense::withTrashed()->find($event->expense_id);
                }),
            'deleted' => $this->withRecorder($forUser($this->deletedQuery(Payment::class, $accountId, $from, $to))
                    ->with('member', 'paymentMethod', 'deletedBy.userable')->latest('deleted_at')->limit(self::LIMIT)->get())
                ->concat($this->withRecorder($forUser($this->deletedQuery(Expense::class, $accountId, $from, $to))
                    ->with('expenseCategory', 'paymentMethod', 'deletedBy.userable')->latest('deleted_at')->limit(self::LIMIT)->get()))
                ->sortByDesc('deleted_at')->take(self::LIMIT)->values(),
            'expenses' => $this->withRecorder($forUser($this->bigExpensesQuery($accountId, $from, $to))
                ->with('expenseCategory', 'paymentMethod')->latest()->limit(self::LIMIT)->get()),
        };
    }

    // the fields that changed in a shift_events "changes" (before / after), [label => [before, after]]
    public function changedFields(ShiftEvent $event): array
    {
        $before = (array) ($event->changes['before'] ?? []);
        $after = (array) ($event->changes['after'] ?? []);
        $labels = ['amount' => 'Amount', 'method' => 'Method', 'paid_at' => 'Date', 'spent_at' => 'Date', 'reference' => 'Reference',
            'item' => 'Item', 'category' => 'Category', 'income_category_id' => 'Income category', 'notes' => 'Notes',
            'payable_type' => 'For', 'payable_id' => 'For no.'];
        $changed = [];
        foreach ($labels as $key => $label) {
            if ((array_key_exists($key, $before) || array_key_exists($key, $after)) && ($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changed[$label] = [$before[$key] ?? null, $after[$key] ?? null];
            }
        }
        return $changed;
    }

    // recorded (created_at) in the range by a login (the morph is a User), of the account
    private function recorded($query, int $accountId, Carbon $from, Carbon $to)
    {
        return $query->where('account_id', $accountId)->where('creatable_type', (new User)->getMorphClass())
            ->where('created_at', '>=', $from)->where('created_at', '<', $to->copy()->addDay());
    }

    private function deletedQuery(string $model, int $accountId, Carbon $from, Carbon $to)
    {
        return $model::onlyTrashed()->where('account_id', $accountId)
            ->where('deleted_at', '>=', $from)->where('deleted_at', '<', $to->copy()->addDay());
    }

    private function editsQuery(int $accountId, Carbon $from, Carbon $to)
    {
        return ShiftEvent::where('account_id', $accountId)->whereIn('action', ['payment_edit', 'expense_edit', 'payment_move'])
            ->where('created_at', '>=', $from)->where('created_at', '<', $to->copy()->addDay());
    }

    // employees' cash outs from the review amount (the account's own are not exceptions)
    private function bigExpensesQuery(int $accountId, Carbon $from, Carbon $to)
    {
        $ownerId = (int) Account::find($accountId)?->user?->id;
        return $this->recorded(Expense::query(), $accountId, $from, $to)->where('creatable_id', '<>', $ownerId)
            ->where('amount', '>=', Account::settingsOf($accountId)?->expenseReviewAmount() ?? 500);
    }

    // the recorder's name on each row (recorder_name)
    private function withRecorder(Collection $rows): Collection
    {
        $users = User::whereIn('id', $rows->pluck('creatable_id')->unique())->with('userable')->get()->keyBy('id');
        return $rows->each(fn ($row) => $row->recorder_name = $users[$row->creatable_id]?->userable?->displayName() ?? 'User #' . $row->creatable_id);
    }
}
