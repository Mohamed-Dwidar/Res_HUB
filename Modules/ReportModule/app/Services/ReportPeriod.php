<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * The period picker shared by the Financial Dashboard, Income, Expenses and P&L (partial Report.partials.period-picker, 2026-11-12).
 * Inputs: period (month | quarter | half | year | custom; '' = the page's default), period_month ('Y-m'), period_quarter ('Y-Qn'),
 * period_half ('Y-Hn'), period_year ('Y') — '' = the current one; custom = date_from / date_to (the date field, may be empty = all
 * dates); compare (previous | last_year | custom | none; '' = the page's default), cmp_from / cmp_to (custom compare).
 * "To date" rule: a period that runs past the business date ends on it, and a previous / last-year comparison is cut at the same
 * point (like for like: 1–12 Nov vs 1–12 Oct). A custom range is never cut.
 * Every report keeps its own date rules: the resolved days go in as date_from / date_to (apply() / compareInput()).
 */
class ReportPeriod
{
    const TYPES = ['month' => 'Month', 'quarter' => 'Quarter', 'half' => 'Half-year', 'year' => 'Year', 'custom' => 'Custom range'];

    const COMPARES = ['previous' => 'Previous period', 'last_year' => 'Same period last year', 'custom' => 'A period I pick', 'none' => 'No comparison'];

    // how many periods back the selects offer
    const BACK = ['month' => 36, 'quarter' => 16, 'half' => 10, 'year' => 8];

    /**
     * [type, from, to (Carbon|null: custom without dates = all), label, compare, cmp_from, cmp_to (null = none), cmp_label]
     */
    public static function resolve(array $input, string $typeDefault = 'month', string $compareDefault = 'none'): array
    {
        $type = array_key_exists($input['period'] ?? '', self::TYPES) ? $input['period'] : $typeDefault;
        $compare = array_key_exists($input['compare'] ?? '', self::COMPARES) ? $input['compare'] : $compareDefault;
        [$from, $to] = self::bounds($type, $input);

        [$today] = Reservation::businessToday();
        $today = $today->copy()->startOfDay();
        $cut = $type !== 'custom' && $from && $from->lte($today) && $to->gt($today);
        $fullTo = $to?->copy();
        if ($cut) {
            $to = $today->copy();
        }

        [$cmpFrom, $cmpTo] = [null, null];
        if ($from && $compare === 'previous') {
            if ($type === 'custom') {
                $days = (int) $from->diffInDays($to) + 1;
                [$cmpFrom, $cmpTo] = [$from->copy()->subDays($days), $from->copy()->subDay()];
            } else {
                $months = ['month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12][$type];
                $cmpFrom = $from->copy()->subMonthsNoOverflow($months);
                $cmpTo = $fullTo->copy()->startOfMonth()->subMonthsNoOverflow($months)->endOfMonth()->startOfDay();
            }
        } elseif ($from && $compare === 'last_year') {
            [$cmpFrom, $cmpTo] = [$from->copy()->subYearNoOverflow(), ($fullTo ?? $to)->copy()->subYearNoOverflow()];
            if ($type !== 'custom') {
                $cmpTo = $cmpTo->copy()->endOfMonth()->startOfDay();
            }
        } elseif ($compare === 'custom') {
            $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value) : null;
            $cmpFrom = $date($input['cmp_from'] ?? null);
            $cmpTo = $date($input['cmp_to'] ?? null) ?: $cmpFrom?->copy();
            if ($cmpFrom && $cmpTo && $cmpTo->lt($cmpFrom)) {
                [$cmpFrom, $cmpTo] = [$cmpTo, $cmpFrom];
            }
        }
        // like for like: the comparison stops at the same point as the cut period
        if ($cut && $cmpFrom && in_array($compare, ['previous', 'last_year'])) {
            $cmpTo = min($cmpTo, $compare === 'last_year' ? $to->copy()->subYearNoOverflow()
                : $cmpFrom->copy()->addDays((int) $from->diffInDays($to)));
        }

        return [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'cut' => $cut,
            'label' => self::label($type, $from, $to, $cut, $fullTo),
            'compare' => $cmpFrom ? $compare : 'none',
            'cmp_from' => $cmpFrom,
            'cmp_to' => $cmpTo,
            'cmp_label' => $cmpFrom ? self::rangeText($cmpFrom, $cmpTo) : null,
        ];
    }

    // the period's own days (before the "to date" cut)
    private static function bounds(string $type, array $input): array
    {
        $pick = (string) ($input['period_' . $type] ?? '');
        $now = today();
        switch ($type) {
            case 'month':
                $from = preg_match('/^\d{4}-\d{2}$/', $pick) ? Carbon::parse($pick . '-01') : $now->copy()->startOfMonth();
                return [$from, $from->copy()->endOfMonth()->startOfDay()];
            case 'quarter':
                $from = preg_match('/^(\d{4})-Q([1-4])$/', $pick, $m) ? Carbon::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1) : $now->copy()->firstOfQuarter();
                return [$from, $from->copy()->addMonthsNoOverflow(2)->endOfMonth()->startOfDay()];
            case 'half':
                $from = preg_match('/^(\d{4})-H([12])$/', $pick, $m) ? Carbon::create((int) $m[1], (int) $m[2] === 1 ? 1 : 7, 1)
                    : Carbon::create($now->year, $now->month <= 6 ? 1 : 7, 1);
                return [$from, $from->copy()->addMonthsNoOverflow(5)->endOfMonth()->startOfDay()];
            case 'year':
                $from = preg_match('/^\d{4}$/', $pick) ? Carbon::create((int) $pick, 1, 1) : $now->copy()->startOfYear();
                return [$from, $from->copy()->endOfYear()->startOfDay()];
        }
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value) : null;
        $from = $date($input['date_from'] ?? null);
        $to = $from ? ($date($input['date_to'] ?? null) ?: $from->copy()) : null;
        if ($from && $to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    private static function label(string $type, ?Carbon $from, ?Carbon $to, bool $cut, ?Carbon $fullTo): string
    {
        if (!$from) {
            return 'All dates';
        }
        $name = match ($type) {
            'month' => $from->format('M Y'),
            'quarter' => 'Q' . $from->quarter . ' ' . $from->year,
            'half' => 'H' . ($from->month <= 6 ? 1 : 2) . ' ' . $from->year,
            'year' => (string) $from->year,
            default => null,
        };
        return $name ? $name . ($cut ? ' (to ' . $to->format('d-m') . ')' : '') : self::rangeText($from, $to);
    }

    public static function rangeText(Carbon $from, Carbon $to): string
    {
        $format = Reservation::DATE_FORMAT;
        return $from->isSameDay($to) ? $from->format($format) : $from->format($format) . ' → ' . $to->format($format);
    }

    // the request with date_from / date_to = the period's days (custom without dates = none)
    public static function apply(array $input, array $period): array
    {
        return ['date_from' => $period['from']?->toDateString(), 'date_to' => $period['to']?->toDateString()] + $input;
    }

    // the request with the comparison's days, or null (no comparison)
    public static function compareInput(array $input, array $period): ?array
    {
        return $period['cmp_from'] ? ['date_from' => $period['cmp_from']->toDateString(), 'date_to' => $period['cmp_to']->toDateString()] + $input : null;
    }

    // the query string that opens another page on the same period (dashboard links): explicit type + compare (the other page's
    // defaults may differ), '' picks left out (= the current one)
    public static function linkQuery(array $input, array $period): array
    {
        $query = ['period' => $period['type'], 'period_' . $period['type'] => $input['period_' . $period['type']] ?? null, 'compare' => $period['compare']];
        if ($period['type'] === 'custom') {
            $query += ['date_from' => $input['date_from'] ?? null, 'date_to' => $input['date_to'] ?? null];
        }
        if ($period['compare'] === 'custom') {
            $query += ['cmp_from' => $input['cmp_from'] ?? null, 'cmp_to' => $input['cmp_to'] ?? null];
        }
        return array_filter($query, fn ($value) => $value !== null && $value !== '');
    }

    // the selects' options: [type => [value => label]] (value '' = the current one)
    public static function options(): array
    {
        $now = today();
        $options = ['month' => [], 'quarter' => [], 'half' => [], 'year' => []];
        $month = $now->copy()->startOfMonth();
        for ($i = 0; $i < self::BACK['month']; $i++, $month->subMonthNoOverflow()) {
            $options['month'][$i ? $month->format('Y-m') : ''] = $month->format('F Y');
        }
        $quarter = $now->copy()->firstOfQuarter();
        for ($i = 0; $i < self::BACK['quarter']; $i++, $quarter->subMonthsNoOverflow(3)) {
            $options['quarter'][$i ? $quarter->year . '-Q' . $quarter->quarter : ''] = 'Q' . $quarter->quarter . ' ' . $quarter->year;
        }
        $half = Carbon::create($now->year, $now->month <= 6 ? 1 : 7, 1);
        for ($i = 0; $i < self::BACK['half']; $i++, $half->subMonthsNoOverflow(6)) {
            $number = $half->month === 1 ? 1 : 2;
            $options['half'][$i ? $half->year . '-H' . $number : ''] = 'H' . $number . ' ' . $half->year . ' (' . ($number === 1 ? 'Jan – Jun' : 'Jul – Dec') . ')';
        }
        for ($i = 0, $year = $now->year; $i < self::BACK['year']; $i++, $year--) {
            $options['year'][$i ? (string) $year : ''] = (string) $year;
        }
        return $options;
    }

    // the change from $previous to $current: [amount, percent (null when the previous is 0), good (bool|null)]
    public static function change($current, $previous, bool $upIsGood = true): array
    {
        $amount = round((float) $current - (float) $previous, 2);
        $percent = round((float) $previous, 2) != 0 ? round($amount / abs((float) $previous) * 100, 1) : null;
        return ['amount' => $amount, 'percent' => $percent, 'good' => $amount == 0 ? null : (($amount > 0) === $upIsGood)];
    }
}
