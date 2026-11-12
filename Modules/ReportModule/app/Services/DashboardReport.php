<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Financial Dashboard (2026-11-12, permission reports.dashboard, no preset): the picked period (ReportPeriod: default this month,
 * compared with the previous period) from the existing reports only — the numbers are theirs:
 * - Income / Expenses / Net profit + margin, the trend per sub-period, Income by subscription type, Expenses by category =
 *   PnlReport on the exact days (Income = the Income report's Net before tax; Expenses = Cash Out without advances);
 * - Owed by members = Σ of the positive member balances on the period's last day (never after the business date): the Member
 *   statement / Members report balance rule (the Outstanding report = today's open rows);
 * - Top 10 members by paid = the Members report's Top members tab; Occupancy % per space = the Occupancy report.
 */
class DashboardReport
{
    // the donuts: the biggest slices, the rest = "Other"
    const SLICES = 7;

    public function data($accountId, array $input): array
    {
        $period = ReportPeriod::resolve($input, 'month', 'previous');
        [$today] = Reservation::businessToday();
        $today = $today->copy()->startOfDay();
        // a custom range without dates (All) = the account's first data → today
        $from = $period['from'] ?? Carbon::parse(ReportDates::earliest());
        $to = $period['to'] ?? $today->copy();

        $pnlReport = app(PnlReport::class);
        $pnl = $pnlReport->data($accountId, $pnlReport->filters(['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
            'columns' => $this->columns($period['type'], $from, $to), 'exact' => 1]));
        if ($pnl['error']) {
            return ['error' => $pnl['error'], 'period' => $period];
        }
        $cmp = null;
        if ($period['cmp_from']) {
            $cmp = $pnlReport->data($accountId, $pnlReport->filters(['date_from' => $period['cmp_from']->toDateString(),
                'date_to' => $period['cmp_to']->toDateString(), 'columns' => 'year', 'exact' => 1]));
            $cmp = $cmp['error'] ? null : $cmp;
        }

        $owedEnd = min($to, $today);
        $owed = $this->owed($accountId, $owedEnd->toDateString());
        $cmpOwedEnd = $cmp ? min($period['cmp_to'], $today) : null;
        $cmpOwed = $cmp ? $this->owed($accountId, $cmpOwedEnd->toDateString()) : null;

        $kpi = fn ($value, $compare, $up = true) => ['value' => $value, 'compare' => $compare,
            'change' => $compare === null ? null : ReportPeriod::change($value, $compare, $up)];
        $totals = $pnl['totals'];

        return [
            'error' => null,
            'period' => $period,
            'range' => ReportPeriod::rangeText($from, $to),
            'cmpRange' => $cmp ? ReportPeriod::rangeText($period['cmp_from'], $period['cmp_to']) : null,
            'kpis' => [
                'income' => $kpi($totals['income']['total'], $cmp['totals']['income']['total'] ?? null),
                'expenses' => $kpi($totals['expenses']['total'], $cmp['totals']['expenses']['total'] ?? null, false),
                'net' => $kpi($totals['net']['total'], $cmp['totals']['net']['total'] ?? null) + ['margin' => $totals['margin']['total'],
                    'cmp_margin' => $cmp['totals']['margin']['total'] ?? null],
                'owed' => $kpi($owed, $cmpOwed, false) + ['date' => $owedEnd->format(Reservation::DATE_FORMAT),
                    'cmp_date' => $cmpOwedEnd?->format(Reservation::DATE_FORMAT)],
            ],
            'charts' => [
                'trend' => ['labels' => array_values($pnl['months']), 'income' => array_values($totals['income']['months']),
                    'expenses' => array_values($totals['expenses']['months']), 'net' => array_values($totals['net']['months'])],
                'income' => $this->slices($pnl['income']),
                'expenses' => $this->slices($pnl['expenses']),
                'members' => $this->topMembers($accountId, $from, $to),
                'occupancy' => $this->occupancy($accountId, $from, $to, $period['cmp_from'], $period['cmp_to']),
            ],
            'dates' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
        ];
    }

    // the trend's sub-periods: days inside a month, months inside a quarter / half / year; a custom range by its length
    private function columns(string $type, Carbon $from, Carbon $to): string
    {
        if ($type === 'month') {
            return 'day';
        }
        if ($type !== 'custom') {
            return 'month';
        }
        $months = $from->copy()->startOfMonth()->diffInMonths($to) + 1;
        return $from->diffInDays($to) <= 62 ? 'day' : ($months <= PnlReport::MAX_COLUMNS ? 'month' : ($months <= PnlReport::MAX_COLUMNS * 3 ? 'quarter' : 'year'));
    }

    // Σ of the positive member balances on a day (the Member statement's lines up to it)
    private function owed($accountId, string $end): float
    {
        return round((float) DB::query()->fromSub(app(MemberStatementReport::class)->balances($accountId, $end), 'b')
            ->where('b.balance', '>', 0)->sum('b.balance'), 2);
    }

    // P&L lines → donut slices (positive totals, biggest first; past SLICES = "Other")
    private function slices(array $rows): array
    {
        $rows = collect($rows)->filter(fn ($row) => $row['total'] > 0)->sortByDesc('total')->values();
        $slices = $rows->take(self::SLICES)->map(fn ($row) => ['label' => $row['label'], 'value' => $row['total']])->all();
        if ($rows->count() > self::SLICES) {
            $slices[] = ['label' => 'Other (' . ($rows->count() - self::SLICES) . ')', 'value' => round($rows->slice(self::SLICES)->sum('total'), 2)];
        }
        return $slices;
    }

    private function topMembers($accountId, Carbon $from, Carbon $to): array
    {
        $report = app(MemberReport::class);
        $filters = $report->filters(['view' => 'top', 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]);
        return $report->order($report->tab($accountId, $filters), 'top')->limit(10)->get(['t.member_id', 't.name', 't.paid'])
            ->map(fn ($row) => ['label' => \Illuminate\Support\Str::limit((string) $row->name, 26), 'value' => round((float) $row->paid, 2), 'id' => (int) $row->member_id])->all();
    }

    // one bar per space (per space + mode when a space has more than one): % in the period [+ the comparison's]
    private function occupancy($accountId, Carbon $from, Carbon $to, ?Carbon $cmpFrom, ?Carbon $cmpTo): array
    {
        $report = app(OccupancyReport::class);
        $read = function (Carbon $from, Carbon $to) use ($report, $accountId) {
            $data = $report->data($accountId, $report->filters(['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]));
            if ($data['error']) {
                return null;
            }
            $bars = [];
            foreach ($data['spaces'] as $space) {
                $modes = $space['totals'];
                foreach ($modes as $mode => $row) {
                    $label = count($modes) > 1 ? $space['space'] . ' · ' . OccupancyReport::MODES[$mode] : $space['space'];
                    $bars[$label] = $row['percent'];
                }
            }
            return $bars;
        };
        $current = $read($from, $to);
        if ($current === null) {
            return ['error' => 'Occupancy needs at most ' . OccupancyReport::MAX_DAYS . ' days.'];
        }
        $compare = $cmpFrom ? $read($cmpFrom, $cmpTo) : null;

        return ['error' => null, 'labels' => array_keys($current), 'values' => array_values($current),
            'compare' => $compare === null ? null : array_map(fn ($label) => $compare[$label] ?? null, array_keys($current))];
    }
}
