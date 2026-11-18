{{-- the Performance report under the toolbar (OccupancyReport::data()): totals box (revenue + per mode), the capacity check, then a table by
     space with its units and subtotals; occupancy columns then the money columns (2026-11-18); $cmpRange = the comparison (Revenue
     cells: compared amount + change) --}}
@php
    $modes = \Modules\ReportModule\app\Services\OccupancyReport::MODES;
    $unitOf = fn ($mode) => ['hourly' => 'h', 'full_day' => 'days', 'period' => 'seats'][$mode];
    $num = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp
@if ($error)
    <div class="notice notice--warning">{{ $error }}</div>
@else
    <div class="money-box">
        {{-- revenue first: the value of the bookings, not money received --}}
        <div class="money-box__item money-box__item--main" title="Value of bookings in the period — not money received (see Income)">
            <span>Revenue @if ($cmpRange && isset($cmp_grand)) @include('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($grand['revenue'], $cmp_grand['revenue'] ?? 0), 'short' => true])@endif</span>
            <b>{{ number_format($grand['revenue'], 2) }}</b>
        </div>
        @forelse ($totals as $mode => $total)
            <div class="money-box__item">
                <span>{{ $modes[$mode] }} · {{ $num($total['booked']) }} / {{ $num($total['available']) }} {{ $unitOf($mode) }}</span>
                <b>{{ $total['percent'] === null ? '-' : $num($total['percent']) . '%' }}</b>
            </div>
        @empty
            <div class="money-box__item money-box__item--main"><span>Occupancy</span><b>-</b></div>
        @endforelse
        <div class="money-box__item"><span>Dates · {{ $days }} {{ $days === 1 ? 'day' : 'days' }}@if ($cmpRange) · vs {{ $cmpRange }}@endif</span><b>{{ $range }}</b></div>
        <div class="money-box__item"><span>Business hours a day</span><b>{{ $hours }}</b></div>
    </div>

    {{-- capacity check: units of capacity 0 / 1 with more reservations at the same time in these dates --}}
    @if ($unset)
        <div class="notice notice--warning mt-4">
            <b>Capacity looks unset</b> — these units have more reservations at the same time than their capacity (Settings › Spaces &amp; Units › the unit › Concurrent usage):
            <ul class="occupancy-unset">
                @foreach ($unset as $item)
                    <li>{{ $item['space'] }} › {{ $item['unit'] }} ({{ $modes[$item['mode']] }}): capacity {{ $item['capacity'] }}, up to {{ $item['peak'] }} at the same time</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- period units: ended over PENDING_DAYS before the dates, not marked left, no renewal — not counted --}}
    @if ($stale)
        <div class="notice notice--warning mt-4">
            <b>Not marked left</b> — these subscriptions ended more than {{ \Modules\ReportModule\app\Services\OccupancyReport::PENDING_DAYS }} days before the dates with no renewal; they are not counted. Mark them Left (or renew them):
            <ul class="occupancy-unset">
                @foreach ($stale as $item)
                    <li>{{ $item->unit }} · #{{ $item->id }} {{ $item->member ?? '-' }} · ended {{ \Illuminate\Support\Carbon::parse($item->last_day)->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="table-wrap mt-5">
        <table class="data-table occupancy-table">
            {{-- every header has an (i) with what the column means (2026-11-18): the reports' .info-tip (opens downward, over the rows) --}}
            @php($th = fn ($label, $tip) => e($label) . ' <span class="info-tip info-tip--th" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"><i data-lucide="info"></i></span>')
            <thead>
                <tr>
                    <th>Space › Unit</th>
                    <th>Mode</th>
                    <th class="text-center">{!! $th('Capacity', 'Seats the unit takes at the same time (its concurrent usage, at least 1).') !!}</th>
                    <th class="text-center">{!! $th('Reservations', 'Counted reservations in the period (cancelled ones not counted); subscriptions: the periods overlapping it.') !!}</th>
                    <th class="text-right">{!! $th('Avg occupied', 'Seats in use: hourly = on average per business hour, full day = on average per day, subscriptions = seats taken.') !!}</th>
                    <th class="text-right">{!! $th('Avg free', 'Capacity − Avg occupied.') !!}</th>
                    <th class="occupancy-table__percent">{!! $th('%', 'A unit: booked ÷ available (hours, days or seats). A space / overall %: the average of its units\' %, only units with reservations in the period (not weighted by capacity).') !!}</th>
                    <th class="text-center">{!! $th('Free today', 'Capacity − what is taken right now (hourly) / today (full day, subscriptions).') !!}</th>
                    <th class="text-right">{!! $th('Standalone', 'Reservations paid on their own: net after discount, before tax (+ their extras; subscriptions prorated by days).') !!}</th>
                    <th class="text-right">{!! $th('From packages', 'Package reservations: their share of the package net, after the package discount, before tax.') !!}</th>
                    <th class="text-right">{!! $th('Revenue', 'Value of bookings in the period — not money received (see Income).') !!}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($spaces as $space)
                    <tr class="occupancy-table__space"><td colspan="11">{{ $space['space'] }}</td></tr>
                    @foreach ($space['units'] as $row)
                        @include('reportmodule::Report.partials.occupancy-row', ['label' => $row['unit'], 'row' => $row, 'note' => null])
                    @endforeach
                    @foreach ($space['totals'] as $mode => $total)
                        @include('reportmodule::Report.partials.occupancy-row', ['label' => $space['space'] . ' total', 'row' => $total, 'class' => 'report-total', 'note' => null])
                    @endforeach
                @empty
                    <tr><td colspan="11" class="empty-state">No active units here.</td></tr>
                @endforelse
            </tbody>
            @if ($totals)
                <tfoot>
                    @foreach ($totals as $mode => $total)
                        @include('reportmodule::Report.partials.occupancy-row', ['label' => 'All spaces', 'row' => $total, 'class' => 'report-total', 'note' => null])
                    @endforeach
                </tfoot>
            @endif
        </table>
    </div>
@endif
