<?php

namespace Modules\ReportModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationStatus;

/**
 * Report 6 — Occupancy (docs/reports-plan.md §6): today's ACTIVE units (by space / unit order), the counted reservations
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
 */
class OccupancyReport
{
    const MODES = ['hourly' => 'Hourly', 'full_day' => 'Full day', 'period' => 'Period'];

    const MAX_DAYS = 731;

    // a period that ended at most this many days before the dates, not marked left, with no renewal = "renewal pending" (still takes
    // its seat); older ones are listed as "not marked left" and do not count
    const PENDING_DAYS = 31;

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

        // today's active units with their mode (the space order, then the unit order)
        $units = DB::table('units as u')
            ->join('spaces as s', 's.id', '=', 'u.space_id')
            ->leftJoin('subscription_types as st', 'st.id', '=', 'u.subscription_type_id')
            ->where('u.account_id', $accountId)->where('u.is_active', 1)->whereNull('u.deleted_at')->whereNull('s.deleted_at')
            ->whereIn('st.booking_mode', array_keys(self::MODES))
            ->when($filters['subscription_type_id'], fn ($query, $id) => $query->where('u.subscription_type_id', $id))
            ->when($filters['space_id'], fn ($query, $id) => $query->where('u.space_id', $id))
            ->orderBy('s.sort_order')->orderBy('s.id')->orderBy('u.sort_order')->orderBy('u.id')
            ->get(['u.id', 'u.space_id', 'u.concurrent_usage', 'st.booking_mode as mode',
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

        // period: booked = occupied seats, available = capacity; hourly / full day: occupied = the average over the time
        $row = function ($mode, $capacity, $booked, $available, $reservations, $free, $extra = []) use ($days, $hours) {
            $slots = $mode === 'hourly' ? $hours * $days : ($mode === 'period' ? 1 : $days);
            $occupied = $slots > 0 ? $booked / $slots : 0;
            return $extra + ['mode' => $mode, 'capacity' => (int) $capacity, 'reservations' => (int) $reservations, 'booked' => round((float) $booked, 2),
                'available' => round((float) $available, 2), 'occupied' => round($occupied, 2), 'free' => round($capacity - $occupied, 2),
                'free_today' => $free, 'percent' => $available > 0 ? round($booked / $available * 100, 1) : null];
        };
        $sum = function ($rows) use ($row) {
            return collect($rows)->groupBy('mode')->map(fn ($group, $mode) => $row($mode, $group->sum('capacity'), $group->sum('booked'),
                    $group->sum('available'), $group->sum('reservations'), $group->sum('free_today'), ['pending' => $group->sum('pending')]))
                ->sortBy(fn ($item, $mode) => array_search($mode, array_keys(self::MODES)))->all();
        };

        $spaces = [];
        $all = [];
        $unset = [];
        foreach ($units->groupBy('space_id') as $spaceUnits) {
            $rows = [];
            foreach ($spaceUnits as $unit) {
                $capacity = max(1, (int) $unit->concurrent_usage);
                $extra = ['unit_id' => $unit->id, 'unit' => $unit->unit, 'space' => $unit->space, 'pending' => 0];
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

        return ['error' => null, 'days' => $days, 'hours' => $hours, 'spaces' => $spaces, 'totals' => $sum($all), 'unset' => $unset, 'stale' => $stale];
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
