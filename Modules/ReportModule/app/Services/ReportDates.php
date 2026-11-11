<?php

namespace Modules\ReportModule\app\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The quick presets inside the reports' date field (layoutmodule::partials.date-range-filter `presets`, custom.js
 * dateRangePresets()): Today | This month | Last month | This year | Until today | All (All = no dates sent: every report reads
 * "no dates" as all dates). Until today = the account's earliest data (reservation start, package start, cash in, cash out) → today.
 */
class ReportDates
{
    private static array $earliest = [];

    public static function presets(): array
    {
        $lastMonth = today()->subMonthNoOverflow();
        return [
            ['label' => 'Today', 'from' => today()->toDateString(), 'to' => today()->toDateString()],
            ['label' => 'This month', 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()],
            ['label' => 'Last month', 'from' => $lastMonth->copy()->startOfMonth()->toDateString(), 'to' => $lastMonth->copy()->endOfMonth()->toDateString()],
            ['label' => 'This year', 'from' => today()->startOfYear()->toDateString(), 'to' => today()->endOfYear()->toDateString()],
            ['label' => 'Until today', 'from' => self::earliest(), 'to' => today()->toDateString(), 'key' => 'until_today'],
            ['label' => 'All', 'from' => null, 'to' => null],
        ];
    }

    // the logged in account's first data date (once per request); none = today
    public static function earliest(): string
    {
        $accountId = (int) Auth::user()?->userable?->ownerAccountId();
        return self::$earliest[$accountId] ??= (string) (DB::query()->fromSub(
            DB::table('reservations')->where('account_id', $accountId)->whereNull('deleted_at')->selectRaw('DATE(MIN(start_at)) as d')
                ->unionAll(DB::table('packages')->where('account_id', $accountId)->whereNull('deleted_at')->selectRaw('MIN(date_from) as d'))
                ->unionAll(DB::table('payments')->where('account_id', $accountId)->whereNull('deleted_at')->selectRaw('MIN(paid_at) as d'))
                ->unionAll(DB::table('expenses')->where('account_id', $accountId)->whereNull('deleted_at')->selectRaw('MIN(spent_at) as d')), 'x')
            ->min('x.d') ?: today()->toDateString());
    }

    // this month [from, to] (the Income report's first range)
    public static function thisMonth(): array
    {
        return [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
    }
}
