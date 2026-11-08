<?php

namespace Modules\PaymentModule\app\Models\Concerns;

use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\UserModule\app\Models\User;

/**
 * "Late entry" (2026-10-27, fraud prevention): a cash in / refund (paid_at) or cash out (spent_at) RECORDED BY AN EMPLOYEE more
 * than Settings › General "late entry hours" (default 12) after the END of its business date (D → D+1 at the cutoff, Pivot 02:00).
 * Worked out from created_at. 2026-11-08 (user): only employees' entries (the owner's never), and not a row whose date the
 * owner changed (date_changed_at: "Date changed by owner" instead). The model gives the date column: const LATE_DATE.
 */
trait LateEntry
{
    // hours from the start of the date (00:00) after which a record is late: 24 + the business day cutoff + the setting
    public static function lateAfterHours($accountId): int
    {
        $account = Account::settingsOf($accountId);
        return 24 + ($account?->cutoffHours() ?? 0) + ($account?->lateEntryHours() ?? 12);
    }

    // the logins of employees (once per request): a late entry is an employee's
    private static function employeeUserIds(): array
    {
        static $ids = null;
        return $ids ??= User::where('userable_type', (new Employee)->getMorphClass())->pluck('id')->flip()->all();
    }

    public function isDateChangedByOwner(): bool
    {
        return $this->date_changed_at !== null;
    }

    public function isLateEntry(): bool
    {
        $date = $this->{static::LATE_DATE};
        return $date && $this->created_at && !$this->isDateChangedByOwner()
            && $this->creatable_type === (new User)->getMorphClass() && isset(self::employeeUserIds()[(int) $this->creatable_id])
            && $this->created_at->gt($date->copy()->startOfDay()->addHours(self::lateAfterHours($this->account_id)));
    }

    public function scopeLateEntries($query, $accountId)
    {
        $model = $query->getModel();
        return $query->whereNull($model->qualifyColumn('date_changed_at'))
            ->where($model->qualifyColumn('creatable_type'), (new User)->getMorphClass())
            ->whereIn($model->qualifyColumn('creatable_id'), User::select('id')->where('userable_type', (new Employee)->getMorphClass()))
            ->whereRaw($model->qualifyColumn('created_at') . ' > ' . $model->qualifyColumn(static::LATE_DATE) . ' + INTERVAL ? HOUR',
                [self::lateAfterHours($accountId)]);
    }
}
