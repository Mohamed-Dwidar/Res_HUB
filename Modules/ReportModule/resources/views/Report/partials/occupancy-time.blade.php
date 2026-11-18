{{-- Performance › Over time (OccupancyReport::overTime(), 2026-11-18): the selection + range, two small line charts (Occupancy %,
     Revenue; last year grey — one axis each, custom.js perfDraw()), then one row per period: Reservations, Booked, %, Standalone,
     From packages, Revenue, each with last year's value + the change (▲ / ▼ %); a running period vs the same dates last year --}}
@php
    $num = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $unitOf = ['hourly' => 'h', 'full_day' => 'days', 'period' => 'seats'];
    $booked = fn (array $values) => collect($modes)->map(fn ($mode) => array_key_exists($mode, $values) ? $num($values[$mode]) . ' ' . $unitOf[$mode] : null)
        ->filter()->implode(' · ') ?: '-';
    $bookedLabel = $modes === ['hourly'] ? 'Hours booked' : 'Booked';
    $percent = fn ($value) => $value === null ? '-' : $num($value) . '%';
    $chart = ['labels' => array_column($rows ?? [], 'label'), 'percent' => array_column($rows ?? [], 'percent'),
        'cmpPercent' => array_map(fn ($row) => $row['cmp']['percent'], $rows ?? []), 'revenue' => array_column($rows ?? [], 'revenue'),
        'cmpRevenue' => array_map(fn ($row) => $row['cmp']['revenue'], $rows ?? [])];
    $runningRow = collect($rows ?? [])->firstWhere('running', true);
    $th = fn ($label, $tip) => e($label) . ' <span class="info-tip info-tip--th" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"><i data-lucide="info"></i></span>';
@endphp
@if ($error)
    <div class="notice notice--warning">{{ $error }}</div>
@else
    <div class="money-box">
        <div class="money-box__item money-box__item--main" title="Value of bookings in the range — not money received (see Income)">
            <span>Revenue @if ($totals) @include('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($totals['revenue'], $totals['cmp']['revenue']), 'short' => true])@endif</span>
            <b>{{ number_format($totals['revenue'] ?? 0, 2) }}</b>
        </div>
        <div class="money-box__item"><span>Occupancy (average of the rows)</span><b>{{ $percent($totals['percent'] ?? null) }}</b></div>
        <div class="money-box__item"><span>{{ $selection }}</span><b>{{ $range }}</b></div>
    </div>

    @if ($rows)
        {{-- the chart data (perfDraw): one value per row, last year grey --}}
        <script type="application/json" data-perf-data>{!! json_encode($chart) !!}</script>
        <div class="dash-charts mt-5">
            <div class="box dash-chart">
                <div class="dash-chart__head"><b>Occupancy %</b></div>
                <div class="dash-chart__canvas dash-chart__canvas--small"><canvas data-perf-chart="percent" aria-label="Occupancy % per row, this year and last year"></canvas></div>
            </div>
            <div class="box dash-chart">
                <div class="dash-chart__head"><b>Revenue</b></div>
                <div class="dash-chart__canvas dash-chart__canvas--small"><canvas data-perf-chart="revenue" aria-label="Revenue per row, this year and last year"></canvas></div>
            </div>
        </div>
    @endif

    <div class="table-wrap mt-5">
        <table class="data-table occupancy-table">
            <thead>
                <tr>
                    <th>{!! $th(\Modules\ReportModule\app\Services\OccupancyReport::ROWS[$size], 'Each row = its calendar period inside the range; the grey line under each value = the same days one year earlier + the change. A running period is compared on the same dates.') !!}</th>
                    <th class="text-center">{!! $th('Reservations', 'Counted reservations in the period (cancelled ones not counted); subscriptions: the periods overlapping it.') !!}</th>
                    <th class="text-right">{!! $th($bookedLabel, 'Hourly: hours booked inside the period; full day: days booked; subscriptions: seats taken.') !!}</th>
                    <th class="text-right">{!! $th('Occupancy %', 'One unit: booked ÷ available. A space / all units: the average of the units\' %, only units with reservations in the period.') !!}</th>
                    <th class="text-right">{!! $th('Standalone', 'Reservations paid on their own: net after discount, before tax (+ their extras; subscriptions prorated by days).') !!}</th>
                    <th class="text-right">{!! $th('From packages', 'Package reservations: their share of the package net, after the package discount, before tax.') !!}</th>
                    <th class="text-right">{!! $th('Revenue', 'Value of bookings in the period — not money received (see Income).') !!}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @include('reportmodule::Report.partials.occupancy-time-row', ['label' => $row['label'], 'row' => $row, 'title' => $row['range'] . ' vs ' . $row['cmp_range']])
                @empty
                    <tr><td colspan="7" class="empty-state">Nothing in this range.</td></tr>
                @endforelse
            </tbody>
            @if ($totals)
                <tfoot>
                    @include('reportmodule::Report.partials.occupancy-time-row', ['label' => 'Total', 'row' => $totals, 'class' => 'report-total', 'title' => 'Sums; Occupancy % and seats = the average of the rows'])
                    {{-- per period, the running (to-date) one left out --}}
                    @if ($average)
                        @include('reportmodule::Report.partials.occupancy-time-row', ['label' => 'Average per ' . strtolower(rtrim(\Modules\ReportModule\app\Services\OccupancyReport::ROWS[$size], 's')), 'row' => $average, 'class' => 'report-total',
                            'title' => 'Average of ' . $average['count'] . ' ' . ($average['count'] === 1 ? 'period' : 'periods') . ($runningRow ? ' — ' . $runningRow['label'] . ' (to date) left out' : ''),
                            'note' => $runningRow ? 'to-date period left out' : null])
                    @endif
                </tfoot>
            @endif
        </table>
    </div>
@endif
