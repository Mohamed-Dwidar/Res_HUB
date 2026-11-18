<?php

namespace Modules\ReportModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationStatus;

/**
 * Report 6 — Performance (was "Occupancy", renamed 2026-11-18; docs/reports-plan.md §6) = occupancy + the revenue of the units: today's ACTIVE units (by space / unit order), the counted reservations
 * overlapping the dates, each CLIPPED to the dates only (no per-day business hours), summed per unit in SQL, rolled up per space.
 Capacity = the unit's concurrent_usage (at least 1) for EVERY mode (user 2026-11-07, replaces "days in use"):
 * - hourly: booked = Σ hours of each reservation inside the dates; available = capacity × business hours a day (Settings › General:
 *   today_hours_from → to) × days; avg occupied = booked ÷ (business hours × days) = seats in use per business hour;
 * - full day / period: booked = Σ days of each reservation inside the dates; available = capacity × days; avg occupied = booked ÷ days.
 * Avg free = capacity − avg occupied; % = booked ÷ available. Free today = capacity − the reservations active now (hourly: running
 * at this moment; full day / period: covering the business date).
 * Capacity check: units with capacity ≤ 1 that have more reservations at the same time inside the dates (hourly: overlapping at a
 * reservation's start; days: on one day) — their capacity looks unset.
 * Dates: required (none = this month); at most MAX_DAYS.
 * Revenue (2026-11-18, revenue()): the VALUE of the bookings in the dates — not money received (that is the Income report):
 * - hourly / full day units: reservations STARTING in the dates; standalone = net_amount (after discount, before tax) + its sales
 *   extras (net); in a package = its share of the package net (net × package after_discount ÷ package price);
 * - period units: every reservation overlapping the dates × (its days inside the dates ÷ its days), standalone / package share alike;
 * - cancelled-like statuses excluded (Paid so far removed 2026-11-18: see the Income / Outstanding reports).
 * Units (2026-11-18): active now OR with counted reservations in the dates (`is_active` 0 = an "inactive" badge); a space / overall
 * % = the AVERAGE of the units' % over the units WITH reservations in the dates (`excluded` = the others, still listed).
 */
class OccupancyReport
{
    const MODES = ['hourly' => 'Hourly', 'full_day' => 'Full day', 'period' => 'Period'];

    const MAX_DAYS = 731;

    // the money columns of a row (summed in the subtotals)
    const MONEY = ['standalone', 'packaged', 'revenue'];

    // a period that ended at most this many days before the dates, not marked left, with no renewal = "renewal pending" (still takes
    // its seat); older ones are listed as "not marked left" and do not count
    const PENDING_DAYS = 31;

    // Over time (2026-11-18): the row sizes (months per row) and the most rows one request builds
    const ROWS = ['month' => 'Months', 'quarter' => 'Quarters', 'half' => 'Half-years', 'year' => 'Years'];

    const ROW_MONTHS = ['month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12];

    const MAX_ROWS = 60;

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        [$from, $to] = ReportDates::thisMonth();
        $dateFrom = $date($input['date_from'] ?? null);
        return [
            'date_from' => $dateFrom ?: $from,
            'date_to' => $dateFrom ? ($date($input['date_to'] ?? null) ?: $dateFrom) : $to,
            'subscription_type_id' => (int) ($input['subscription_type_id'] ?? 0),
            'space_id' => (int) ($input['space_id'] ?? 0),
            'unit_id' => (int) ($input['unit_id'] ?? 0),
        ];
    }

    public function rangeText(array $filters): string
    {
        $format = Reservation::DATE_FORMAT;
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to']);
        return $from->isSameDay($to) ? $from->format($format) : $from->format($format) . ' → ' . $to->format($format);
    }

    /**
     * ['error' => ?string, 'days', 'hours' (business hours a day), 'spaces' => [[space, units => [rows], totals => [mode => row]]],
     *  'totals' => [mode => row], 'unset' => [units whose capacity looks unset]]; a row: unit, space, mode, capacity, reservations,
     *  booked, available, occupied (avg), free (avg), free_today, percent
     */
    public function data($accountId, array $filters): array
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $days = (int) $from->diffInDays($to) + 1;
        if ($days > self::MAX_DAYS) {
            return ['error' => 'Choose at most ' . self::MAX_DAYS . ' days.', 'days' => $days, 'hours' => 0, 'spaces' => [], 'totals' => [], 'unset' => [], 'stale' => []];
        }
        [$hoursFrom, $hoursTo] = Account::find($accountId)?->todayHours() ?? [8, 24];
        $hours = max(0, $hoursTo - $hoursFrom);
        $end = $to->copy()->addDay();

        // the units active now OR with counted reservations in the dates (2026-11-18), with their mode (space order, then unit order)
        $notCountedIn = implode(',', array_map('intval', ReservationStatus::notCountedIds()) ?: [0]);
        $units = DB::table('units as u')
            ->join('spaces as s', 's.id', '=', 'u.space_id')
            ->leftJoin('subscription_types as st', 'st.id', '=', 'u.subscription_type_id')
            ->where('u.account_id', $accountId)->whereNull('u.deleted_at')->whereNull('s.deleted_at')
            ->where(fn ($query) => $query->where('u.is_active', 1)->orWhereExists(fn ($query) => $query->from('reservations as rr')
                ->whereColumn('rr.unit_id', 'u.id')->whereNull('rr.deleted_at')->whereRaw("rr.reservation_status_id NOT IN ({$notCountedIn})")
                ->where('rr.start_at', '<', $end)->whereRaw('DATE(GREATEST(rr.end_at, rr.start_at)) >= ?', [$from->toDateString()])))
            ->whereIn('st.booking_mode', array_keys(self::MODES))
            ->when($filters['subscription_type_id'], fn ($query, $id) => $query->where('u.subscription_type_id', $id))
            ->when($filters['space_id'], fn ($query, $id) => $query->where('u.space_id', $id))
            ->when($filters['unit_id'] ?? 0, fn ($query, $id) => $query->where('u.id', $id))
            ->orderBy('s.sort_order')->orderBy('s.id')->orderBy('u.sort_order')->orderBy('u.id')
            ->get(['u.id', 'u.space_id', 'u.concurrent_usage', 'u.is_active', 'st.booking_mode as mode',
                DB::raw("COALESCE(NULLIF(u.name_en, ''), u.name_ar) as unit"), DB::raw("COALESCE(NULLIF(s.name_en, ''), s.name_ar) as space")]);
        $notCounted = array_map('intval', ReservationStatus::notCountedIds()) ?: [0];
        $hourlyIds = $units->where('mode', 'hourly')->pluck('id')->all() ?: [0];
        $dayIds = $units->where('mode', 'full_day')->pluck('id')->all() ?: [0];
        $periodIds = $units->where('mode', 'period')->pluck('id')->all() ?: [0];

        // hourly: the clipped hours (+ the reservations) per unit
        $hourly = DB::table('reservations')
            ->where('account_id', $accountId)->whereNull('deleted_at')->whereNotIn('reservation_status_id', $notCounted)
            ->whereIn('unit_id', $hourlyIds)->where('start_at', '<', $end)->where('end_at', '>', $from)
            ->groupBy('unit_id')
            ->selectRaw('unit_id, COUNT(*) as reservations, SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, GREATEST(start_at, ?), LEAST(end_at, ?)))) / 60 as booked',
                [$from, $end])
            ->get()->keyBy('unit_id');

        // full day: Σ days of each reservation inside the dates (its last day = DATE of the real end), + the reservations
        $lastDay = 'DATE(GREATEST(end_at, start_at))';
        $dayRows = DB::table('reservations')
            ->where('account_id', $accountId)->whereNull('deleted_at')->whereNotIn('reservation_status_id', $notCounted)
            ->whereIn('unit_id', $dayIds)->where('start_at', '<', $end)->whereRaw($lastDay . ' >= ?', [$from->toDateString()])
            ->groupBy('unit_id')
            ->selectRaw("unit_id, COUNT(*) as reservations,
                SUM(GREATEST(0, DATEDIFF(LEAST({$lastDay}, ?), GREATEST(DATE(start_at), ?)) + 1)) as booked", [$to->toDateString(), $from->toDateString()])
            ->get()->keyBy('unit_id');

        // period: seats (user 2026-11-07) — in the dates and today (free today)
        [$businessDate] = Reservation::businessToday();
        $businessDate = $businessDate->toDateString();
        $seats = $this->seats($accountId, $periodIds, $notCounted, $from->toDateString(), $to->toDateString(), min($to->toDateString(), $businessDate));
        $seatsToday = $this->seats($accountId, $periodIds, $notCounted, $businessDate, $businessDate, $businessDate);
        $stale = $this->stale($accountId, $periodIds, $notCounted, $from->copy()->subDays(self::PENDING_DAYS)->toDateString());

        // free today: the reservations active now (hourly) / covering the business date (full day)
        $activeNow = DB::table('reservations')
            ->where('account_id', $accountId)->whereNull('deleted_at')->whereNotIn('reservation_status_id', $notCounted)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('unit_id', $hourlyIds)->where('start_at', '<=', now())->where('end_at', '>', now()))
                ->orWhere(fn ($query) => $query->whereIn('unit_id', $dayIds)->whereRaw('DATE(start_at) <= ?', [$businessDate])
                    ->whereRaw($lastDay . ' >= ?', [$businessDate])))
            ->groupBy('unit_id')->selectRaw('unit_id, COUNT(*) as n')->pluck('n', 'unit_id');

        // the capacity check (units of capacity ≤ 1 only): the most reservations at the same time inside the dates
        $single = $units->filter(fn ($unit) => (int) $unit->concurrent_usage <= 1);
        $singleHourly = $single->where('mode', 'hourly')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $singleDays = $single->whereIn('mode', ['full_day', 'period'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $peaks = [];
        if ($singleHourly) {
            // the most bookings running at the same moment per unit: a sweep over the starts (+1) and ends (−1) of its bookings
            $events = [];
            foreach (DB::table('reservations')->where('account_id', $accountId)->whereNull('deleted_at')
                ->whereNotIn('reservation_status_id', $notCounted)->whereIn('unit_id', $singleHourly)
                ->where('start_at', '<', $end)->where('end_at', '>', $from)->get(['unit_id', 'start_at', 'end_at']) as $booking) {
                $events[$booking->unit_id][] = [$booking->start_at, 1];
                $events[$booking->unit_id][] = [$booking->end_at, -1];
            }
            foreach ($events as $unitId => $list) {
                // an end before a start at the same moment (back to back is not an overlap)
                usort($list, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                $running = 0;
                foreach ($list as [, $step]) {
                    $running += $step;
                    $peaks[$unitId] = max($peaks[$unitId] ?? 0, $running);
                }
            }
        }
        if ($singleDays) {
            // the bookings of these units inside the dates (few: one-seat units), counted per day in PHP
            $perDay = [];
            $bookings = DB::table('reservations')->where('account_id', $accountId)->whereNull('deleted_at')
                ->whereNotIn('reservation_status_id', $notCounted)->whereIn('unit_id', $singleDays)
                ->where('start_at', '<', $end)->whereRaw($lastDay . ' >= ?', [$from->toDateString()])
                ->selectRaw("unit_id, GREATEST(DATE(start_at), ?) as first_day, LEAST({$lastDay}, ?) as last_day", [$from->toDateString(), $to->toDateString()])
                ->get();
            foreach ($bookings as $booking) {
                for ($day = Carbon::parse($booking->first_day); $day->lte(Carbon::parse($booking->last_day)); $day->addDay()) {
                    $key = $booking->unit_id . '|' . $day->toDateString();
                    $perDay[$key] = ($perDay[$key] ?? 0) + 1;
                }
            }
            foreach ($perDay as $key => $count) {
                $unitId = (int) strtok($key, '|');
                $peaks[$unitId] = max($peaks[$unitId] ?? 0, $count);
            }
        }

        $money = $this->revenue($accountId, $notCounted, $units, $from, $to);

        // period: booked = occupied seats, available = capacity; hourly / full day: occupied = the average over the time
        $row = function ($mode, $capacity, $booked, $available, $reservations, $free, $extra = []) use ($days, $hours) {
            $slots = $mode === 'hourly' ? $hours * $days : ($mode === 'period' ? 1 : $days);
            $occupied = $slots > 0 ? $booked / $slots : 0;
            $result = $extra + ['mode' => $mode, 'capacity' => (int) $capacity, 'reservations' => (int) $reservations, 'booked' => round((float) $booked, 2),
                'available' => round((float) $available, 2), 'occupied' => round($occupied, 2), 'free' => round($capacity - $occupied, 2),
                'free_today' => $free, 'percent' => $available > 0 ? round($booked / $available * 100, 1) : null];
            foreach (self::MONEY as $key) {
                $result[$key] = round((float) ($extra[$key] ?? 0), 2);
            }
            return $result;
        };
        // a subtotal: the % only over the units with reservations in the dates (excluded = the others, 2026-11-18)
        $sum = function ($rows) use ($row) {
            return collect($rows)->groupBy('mode')->map(function ($group, $mode) use ($row) {
                $used = $group->where('reservations', '>', 0);
                $total = $row($mode, $group->sum('capacity'), $group->sum('booked'), $group->sum('available'), $group->sum('reservations'),
                    $group->sum('free_today'), ['pending' => $group->sum('pending')] + collect(self::MONEY)->mapWithKeys(fn ($key) => [$key => $group->sum($key)])->all());
                // the average of the units' % (not capacity-weighted, 2026-11-18): one big unit with one booking doesn't pull it down
                $percents = $used->pluck('percent')->filter(fn ($percent) => $percent !== null);
                $total['percent'] = $percents->isNotEmpty() ? round($percents->avg(), 1) : null;
                $total['excluded'] = $group->count() - $used->count();
                return $total;
            })->sortBy(fn ($item, $mode) => array_search($mode, array_keys(self::MODES)))->all();
        };

        $spaces = [];
        $all = [];
        $unset = [];
        foreach ($units->groupBy('space_id') as $spaceUnits) {
            $rows = [];
            foreach ($spaceUnits as $unit) {
                $capacity = max(1, (int) $unit->concurrent_usage);
                $extra = ['unit_id' => $unit->id, 'unit' => $unit->unit, 'space' => $unit->space, 'pending' => 0, 'inactive' => !$unit->is_active]
                    + collect(self::MONEY)->mapWithKeys(fn ($key) => [$key => (float) ($money[$unit->id][$key] ?? 0)])->all();
                if ($unit->mode === 'period') {
                    $seat = $seats->get($unit->id);
                    $rows[] = $row('period', $capacity, $seat->occupied ?? 0, $capacity, $seat->reservations ?? 0,
                        $capacity - (int) ($seatsToday->get($unit->id)->occupied ?? 0), ['pending' => (int) ($seat->pending ?? 0)] + $extra);
                } else {
                    $data = $unit->mode === 'hourly' ? $hourly->get($unit->id) : $dayRows->get($unit->id);
                    $available = $capacity * ($unit->mode === 'hourly' ? $hours * $days : $days);
                    $rows[] = $row($unit->mode, $capacity, $data->booked ?? 0, $available, $data->reservations ?? 0,
                        $capacity - (int) ($activeNow[$unit->id] ?? 0), $extra);
                }
                if (($peaks[$unit->id] ?? 0) > $capacity) {
                    $unset[] = ['unit' => $unit->unit, 'space' => $unit->space, 'mode' => $unit->mode, 'capacity' => (int) $unit->concurrent_usage, 'peak' => (int) $peaks[$unit->id]];
                }
            }
            $spaces[] = ['space' => $spaceUnits->first()->space, 'units' => $rows, 'totals' => $sum($rows)];
            $all = array_merge($all, $rows);
        }

        $grand = collect(self::MONEY)->mapWithKeys(fn ($key) => [$key => round(collect($all)->sum($key), 2)])->all();

        return ['error' => null, 'days' => $days, 'hours' => $hours, 'spaces' => $spaces, 'totals' => $sum($all), 'grand' => $grand,
            'unset' => $unset, 'stale' => $stale];
    }

    /**
     * Over time (2026-11-18): the chosen unit / space / everything ($filters: type, space, unit) per calendar row (month / quarter /
     * half-year / year) inside [$from, $to] — each row = data() of its days (cut to the range: a running row ends on the range end,
     * i.e. the business date), and the same days one year earlier (like for like: a full row = the whole period last year, a cut row
     * = the same dates). A row: label, from, to, cut, reservations, booked [mode => Σ booked], percent (the average of the units' %
     * over units with reservations, like the subtotals), standalone, packaged, revenue, cmp (the same keys, last year).
     * Totals: Σ reservations / booked (seats = the average of the rows) / money, % = the average of the rows' %. Average: each figure
     * ÷ the rows, the running (to-date) row left out (`running`).
     */
    public function overTime($accountId, array $filters, \Carbon\Carbon $from, \Carbon\Carbon $to, string $size): array
    {
        $months = self::ROW_MONTHS[$size] ?? 1;
        $rows = [];
        $start = $from->copy()->startOfMonth();
        // align to the row size (quarters / halves / years start in Jan, Apr, Jul, Oct …)
        $start->month(intdiv($start->month - 1, $months) * $months + 1);
        if (intdiv(($to->year - $start->year) * 12 + $to->month - $start->month, $months) + 1 > self::MAX_ROWS) {
            return ['error' => 'Too many rows: choose a shorter range or bigger rows (at most ' . self::MAX_ROWS . ').', 'rows' => [], 'totals' => null, 'average' => null, 'modes' => []];
        }
        [$businessDate] = Reservation::businessToday();
        while ($start->lte($to)) {
            $periodEnd = $start->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
            $rowFrom = $start->lt($from) ? $from->copy() : $start->copy();
            $rowTo = $periodEnd->gt($to) ? $to->copy() : $periodEnd->copy();
            $full = $rowFrom->eq($start) && $rowTo->eq($periodEnd);
            $cmpFrom = $rowFrom->copy()->subYearNoOverflow();
            $cmpTo = $full ? $periodEnd->copy()->subYearNoOverflow()->endOfMonth()->startOfDay() : $rowTo->copy()->subYearNoOverflow();
            $row = $this->summary($accountId, $filters, $rowFrom, $rowTo);
            $row['cmp'] = $this->summary($accountId, $filters, $cmpFrom, $cmpTo);
            $row['label'] = $this->rowLabel($size, $start) . ($full ? '' : ' (' . $rowFrom->format('d-m') . ' → ' . $rowTo->format('d-m') . ')');
            $row['cut'] = !$full;
            // running = the period still going: cut on the business date (left out of the Average, 2026-11-18)
            $row['running'] = $rowTo->lt($periodEnd) && $rowTo->isSameDay($businessDate);
            $row['range'] = $this->rangeText(['date_from' => $rowFrom->toDateString(), 'date_to' => $rowTo->toDateString()]);
            $row['cmp_range'] = $this->rangeText(['date_from' => $cmpFrom->toDateString(), 'date_to' => $cmpTo->toDateString()]);
            $rows[] = $row;
            $start->addMonthsNoOverflow($months);
        }

        $total = fn (array $list) => [
            'reservations' => array_sum(array_column($list, 'reservations')),
            'booked' => collect(array_keys(self::MODES))->mapWithKeys(function ($mode) use ($list) {
                $values = collect($list)->map(fn ($row) => $row['booked'][$mode] ?? null)->filter(fn ($value) => $value !== null);
                // seats are not added up over time: their average
                return $values->isEmpty() ? [] : [$mode => round($mode === 'period' ? $values->avg() : $values->sum(), 2)];
            })->all(),
            'percent' => ($percents = collect($list)->pluck('percent')->filter(fn ($value) => $value !== null))->isNotEmpty() ? round($percents->avg(), 1) : null,
        ] + collect(self::MONEY)->mapWithKeys(fn ($key) => [$key => round(array_sum(array_column($list, $key)), 2)])->all();
        $totals = $rows ? $total($rows) + ['cmp' => $total(array_column($rows, 'cmp'))] : null;
        // the Average row (2026-11-18): per period over the rows, the running one left out (none = no Average)
        $average = function (array $list) {
            $avg = fn ($values) => ($values = collect($values)->filter(fn ($value) => $value !== null))->isNotEmpty() ? $values->avg() : null;
            return [
                'reservations' => round($avg(array_column($list, 'reservations')) ?? 0, 1),
                'booked' => collect(array_keys(self::MODES))->mapWithKeys(fn ($mode) => ($value = $avg(array_map(fn ($row) => $row['booked'][$mode] ?? null, $list))) === null ? [] : [$mode => round($value, 2)])->all(),
                'percent' => ($value = $avg(array_column($list, 'percent'))) === null ? null : round($value, 1),
            ] + collect(self::MONEY)->mapWithKeys(fn ($key) => [$key => round($avg(array_column($list, $key)) ?? 0, 2)])->all();
        };
        $complete = array_values(array_filter($rows, fn ($row) => !$row['running']));
        $averages = $complete ? $average($complete) + ['cmp' => $average(array_column($complete, 'cmp')), 'count' => count($complete)] : null;
        $modes = collect($rows)->flatMap(fn ($row) => array_keys($row['booked']))->unique()
            ->sortBy(fn ($mode) => array_search($mode, array_keys(self::MODES)))->values()->all();

        return ['error' => null, 'rows' => $rows, 'totals' => $totals, 'average' => $averages, 'modes' => $modes];
    }

    // one row of Over time: data() of the days rolled up over every unit of the selection
    private function summary($accountId, array $filters, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $data = $this->data($accountId, ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()] + $filters);
        $units = collect($data['spaces'])->flatMap(fn ($space) => $space['units']);
        $percents = $units->where('reservations', '>', 0)->pluck('percent')->filter(fn ($value) => $value !== null);

        return [
            'reservations' => (int) $units->sum('reservations'),
            'booked' => $units->groupBy('mode')->map(fn ($group) => round($group->sum('booked'), 2))->all(),
            'percent' => $percents->isNotEmpty() ? round($percents->avg(), 1) : null,
        ] + collect(self::MONEY)->mapWithKeys(fn ($key) => [$key => round((float) $units->sum($key), 2)])->all();
    }

    private function rowLabel(string $size, \Carbon\Carbon $start): string
    {
        return match ($size) {
            'quarter' => 'Q' . $start->quarter . ' ' . $start->year,
            'half' => 'H' . ($start->month <= 6 ? 1 : 2) . ' ' . $start->year,
            'year' => (string) $start->year,
            default => $start->format('M Y'),
        };
    }

    /**
     * The revenue per unit in [from, to] (see the class comment): [unit id => [standalone, packaged, revenue]].
     * Two grouped queries: the dated units (hourly / full day: reservations starting in the dates + their sales extras) and the
     * period units (overlapping reservations × the share of their days inside the dates).
     */
    public function revenue($accountId, array $notCounted, $units, Carbon $from, Carbon $to): array
    {
        $out = [];
        $end = $to->copy()->addDay();
        $datedIds = $units->whereIn('mode', ['hourly', 'full_day'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $periodIds = $units->where('mode', 'period')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $morph = (new Reservation)->getMorphClass();
        // a package reservation's share of its package: net ÷ the package price (the package's discount follows it)
        $share = 'CASE WHEN r.package_id > 0 AND p.amount > 0 THEN r.net_amount / p.amount ELSE 0 END';
        $columns = fn (string $factor, string $extras) => "r.unit_id,
            SUM(CASE WHEN r.package_id = 0 THEN (r.net_amount{$extras}) * {$factor} ELSE 0 END) AS standalone,
            SUM(({$share}) * p.after_discount * {$factor}) AS packaged";
        $base = fn () => DB::table('reservations as r')
            ->leftJoin('packages as p', fn ($join) => $join->on('p.id', '=', 'r.package_id')->where('r.package_id', '>', 0))
            ->where('r.account_id', $accountId)->whereNull('r.deleted_at')->whereNotIn('r.reservation_status_id', $notCounted)
            ->groupBy('r.unit_id');

        $rows = collect();
        if ($datedIds) {
            $extras = DB::table('extras')->where('extrable_type', $morph)->where('type', 'extra')->whereNull('deleted_at')
                ->groupBy('extrable_id')->selectRaw('extrable_id, SUM(COALESCE(tax_net, amount)) AS net');
            $rows = $rows->concat($base()->leftJoinSub($extras, 'x', 'x.extrable_id', '=', 'r.id')
                ->whereIn('r.unit_id', $datedIds)->where('r.start_at', '>=', $from)->where('r.start_at', '<', $end)
                ->selectRaw($columns('1', ' + COALESCE(x.net, 0)'))->get());
        }
        if ($periodIds) {
            $last = 'DATE(GREATEST(r.end_at, r.start_at))';
            $factor = "(DATEDIFF(LEAST({$last}, '" . $to->toDateString() . "'), GREATEST(DATE(r.start_at), '" . $from->toDateString() . "')) + 1)"
                . " / (DATEDIFF({$last}, DATE(r.start_at)) + 1)";
            $rows = $rows->concat($base()->whereIn('r.unit_id', $periodIds)->whereNotNull('r.end_at')
                ->whereRaw('DATE(r.start_at) <= ?', [$to->toDateString()])->whereRaw($last . ' >= ?', [$from->toDateString()])
                ->selectRaw($columns($factor, ''))->get());
        }
        foreach ($rows as $row) {
            $out[$row->unit_id] = ['standalone' => (float) $row->standalone, 'packaged' => (float) $row->packaged,
                'revenue' => (float) $row->standalone + (float) $row->packaged];
        }

        return $out;
    }

    /**
     * The comparison period's revenue + % on each row (cmp_revenue, cmp_percent): units by id, space subtotals by space + mode, the
     * totals by mode, the grand revenue.
     */
    public function compare(array $data, array $cmp): array
    {
        $units = [];
        $spaces = [];
        foreach ($cmp['spaces'] ?? [] as $space) {
            foreach ($space['units'] as $row) {
                $units[$row['unit_id']] = $row;
            }
            foreach ($space['totals'] as $mode => $row) {
                $spaces[$space['space'] . '|' . $mode] = $row;
            }
        }
        $put = fn (array $row, ?array $other) => $row + ['cmp_revenue' => (float) ($other['revenue'] ?? 0), 'cmp_percent' => $other['percent'] ?? null];
        foreach ($data['spaces'] as &$space) {
            foreach ($space['units'] as &$row) {
                $row = $put($row, $units[$row['unit_id']] ?? null);
            }
            unset($row);
            foreach ($space['totals'] as $mode => &$row) {
                $row = $put($row, $spaces[$space['space'] . '|' . $mode] ?? null);
            }
            unset($row);
        }
        unset($space);
        foreach ($data['totals'] as $mode => &$row) {
            $row = $put($row, $cmp['totals'][$mode] ?? null);
        }
        unset($row);
        $data['cmp_grand'] = $cmp['grand'] ?? null;

        return $data;
    }

    /**
     * Period units: the seats taken per unit in [from, to] (Y-m-d) — one seat per renewal chain: a period overlapping the dates whose
     * renewal does not also overlap them (the chain counts once, on its latest period), + a period that ENDED in the PENDING_DAYS
     * before the dates, not marked left, with no renewal (renewal pending: the member is still there). pending = the taken seats
     * whose period ended before $pendingBefore (min(to, the business date)) with no renewal, not left. reservations = the periods
     * overlapping the dates.
     */
    private function seats($accountId, array $unitIds, array $notCounted, string $from, string $to, string $pendingBefore)
    {
        $notCounted = implode(',', $notCounted);
        $last = 'DATE(GREATEST(r.end_at, r.start_at))';
        $renewal = "SELECT 1 FROM reservations n WHERE n.renewal_of = r.id AND n.deleted_at IS NULL AND n.reservation_status_id NOT IN ({$notCounted})";
        $renewalIn = "EXISTS ({$renewal} AND DATE(n.start_at) <= ? AND DATE(GREATEST(n.end_at, n.start_at)) >= ?)";
        $renewalAny = "EXISTS ({$renewal})";

        $since = Carbon::parse($from)->subDays(self::PENDING_DAYS)->toDateString();

        return collect(DB::select("
            SELECT r.unit_id,
                SUM(CASE WHEN DATE(r.start_at) <= ? AND {$last} >= ? THEN NOT {$renewalIn}
                         WHEN {$last} < ? AND {$last} >= ? AND r.left_at IS NULL THEN NOT {$renewalAny} ELSE 0 END) as occupied,
                SUM({$last} < ? AND {$last} >= ? AND r.left_at IS NULL AND NOT {$renewalAny}) as pending,
                SUM(DATE(r.start_at) <= ? AND {$last} >= ?) as reservations
            FROM reservations r
            WHERE r.account_id = ? AND r.deleted_at IS NULL AND r.reservation_status_id NOT IN ({$notCounted}) AND r.end_at IS NOT NULL
              AND r.unit_id IN (" . implode(',', array_map('intval', $unitIds)) . ") AND DATE(r.start_at) <= ?
            GROUP BY r.unit_id",
            [$to, $from, $to, $from, $from, $since, $pendingBefore, $since, $to, $from, $accountId, $to]))->keyBy('unit_id');
    }

    // period units: ended before $before (the dates' start − PENDING_DAYS), not marked left, no renewal — not counted, listed so
    // they can be marked left
    private function stale($accountId, array $unitIds, array $notCounted, string $before): array
    {
        $notCounted = implode(',', $notCounted);
        return DB::select("
            SELECT r.id, COALESCE(NULLIF(u.name_en, ''), u.name_ar) as unit, m.name as member, DATE(GREATEST(r.end_at, r.start_at)) as last_day
            FROM reservations r JOIN units u ON u.id = r.unit_id LEFT JOIN members m ON m.id = r.member_id
            WHERE r.account_id = ? AND r.deleted_at IS NULL AND r.reservation_status_id NOT IN ({$notCounted}) AND r.left_at IS NULL
              AND r.unit_id IN (" . implode(',', array_map('intval', $unitIds)) . ") AND DATE(GREATEST(r.end_at, r.start_at)) < ?
              AND NOT EXISTS (SELECT 1 FROM reservations n WHERE n.renewal_of = r.id AND n.deleted_at IS NULL AND n.reservation_status_id NOT IN ({$notCounted}))
            ORDER BY u.sort_order, last_day", [$accountId, $before]);
    }
}
