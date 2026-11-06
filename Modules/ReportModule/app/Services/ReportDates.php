<?php

namespace Modules\ReportModule\app\Services;

/**
 * The quick presets inside the reports' date field (layoutmodule::partials.date-range-filter `presets`, custom.js
 * dateRangePresets()): Today | This month | Last month | All (All = no dates sent: every report reads "no dates" as all dates).
 */
class ReportDates
{
    public static function presets(): array
    {
        $lastMonth = today()->subMonthNoOverflow();
        return [
            ['label' => 'Today', 'from' => today()->toDateString(), 'to' => today()->toDateString()],
            ['label' => 'This month', 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()],
            ['label' => 'Last month', 'from' => $lastMonth->copy()->startOfMonth()->toDateString(), 'to' => $lastMonth->copy()->endOfMonth()->toDateString()],
            ['label' => 'All', 'from' => null, 'to' => null],
        ];
    }

    // this month [from, to] (the Income report's first range)
    public static function thisMonth(): array
    {
        return [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
    }
}
