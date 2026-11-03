<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\AccountModule\app\Models\Account;
use Modules\UserModule\app\Models\User;

/**
 * WHICH cash rows a login may see (docs/roles-plan.md R1) — the ONE place every Payments list, summary and export asks:
 * - the owner: every row, any dates;
 * - payments.view_all / expenses.view_all: every row of that kind (all employees + the owner), any dates;
 * - payments.date_range / expenses.date_range: own rows, any dates;
 * - else: own rows of ONE day (today by default), as before roles.
 * Cash in / withholding / refunds = payments rules; cash out (expenses) = expenses rules. Seeing a row never allows editing it
 * (PaymentService / ExpenseService canEdit()).
 */
class CashScope
{
    private function user(?User $user): ?User
    {
        return $user ?? Auth::user();
    }

    private function isOwner(?User $user): bool
    {
        return $user?->userable instanceof Account;
    }

    private function allows(?User $user, string $ability): bool
    {
        return $this->isOwner($user) || ($user && Gate::forUser($user)->allows($ability));
    }

    // whose cash in / withholding / refunds: null = everyone, else this login's users.id
    public function paymentsCollector(?User $user = null): ?int
    {
        $user = $this->user($user);
        return $this->allows($user, 'payments.view_all') ? null : $user?->id;
    }

    // whose cash out
    public function expensesCollector(?User $user = null): ?int
    {
        $user = $this->user($user);
        return $this->allows($user, 'expenses.view_all') ? null : $user?->id;
    }

    // any dates (date range + Today / This month / All); false = one day
    public function paymentsRange(?User $user = null): bool
    {
        $user = $this->user($user);
        return $this->allows($user, 'payments.view_all') || $this->allows($user, 'payments.date_range');
    }

    public function expensesRange(?User $user = null): bool
    {
        $user = $this->user($user);
        return $this->allows($user, 'expenses.view_all') || $this->allows($user, 'expenses.date_range');
    }

    // a Payments page tab: in = cash in; all / out = payments + expenses together (refunds are payments): both must allow it
    public function rangeFor(string $type, ?User $user = null): bool
    {
        return $type === 'in' ? $this->paymentsRange($user) : $this->paymentsRange($user) && $this->expensesRange($user);
    }

    // the tab may show other logins' rows (the "By" column + the "Created By" filter)
    public function seesOthers(string $type, ?User $user = null): bool
    {
        return $type === 'in'
            ? $this->paymentsCollector($user) === null
            : $this->paymentsCollector($user) === null || $this->expensesCollector($user) === null;
    }
}
