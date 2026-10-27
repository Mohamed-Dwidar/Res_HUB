<?php

namespace Modules\PaymentModule\app\Models\Concerns;

use Modules\AccountModule\app\Models\Account;

/**
 * "Late entry" (2026-10-27, fraud prevention): a cash in / refund (paid_at) or cash out (spent_at) RECORDED more than
 * Settings › General "late entry hours" (default 12) after the END of its business date (D → D+1 at the cutoff, Pivot 02:00).
 * Nothing stored: worked out from created_at. The model gives the date column: const LATE_DATE.
 */
trait LateEntry
{
    // hours from the start of the date (00:00) after which a record is late: 24 + the business day cutoff + the setting
    public static function lateAfterHours($accountId): int
    {
        $account = Account::settingsOf($accountId);
        return 24 + ($account?->cutoffHours() ?? 0) + ($account?->lateEntryHours() ?? 12);
    }

    public function isLateEntry(): bool
    {
        $date = $this->{static::LATE_DATE};
        return $date && $this->created_at
            && $this->created_at->gt($date->copy()->startOfDay()->addHours(self::lateAfterHours($this->account_id)));
    }

    public function scopeLateEntries($query, $accountId)
    {
        $model = $query->getModel();
        return $query->whereRaw($model->qualifyColumn('created_at') . ' > ' . $model->qualifyColumn(static::LATE_DATE) . ' + INTERVAL ? HOUR',
            [self::lateAfterHours($accountId)]);
    }
}
