{{-- the Occupancy report under the toolbar (OccupancyReport::data()): totals box per mode, the capacity check, then a table by space with its
     units and subtotals --}}
@php
    $modes = \Modules\ReportModule\app\Services\OccupancyReport::MODES;
    $unitOf = fn ($mode) => ['hourly' => 'h', 'full_day' => 'days', 'period' => 'seats'][$mode];
    $num = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp
@if ($error)
    <div class="notice notice--warning">{{ $error }}</div>
@else
    <div class="money-box">
        @forelse ($totals as $mode => $total)
            <div @class(['money-box__item', 'money-box__item--main' => $loop->first])>
                <span>{{ $modes[$mode] }} · {{ $num($total['booked']) }} / {{ $num($total['available']) }} {{ $unitOf($mode) }}</span>
                <b>{{ $total['percent'] === null ? '-' : $num($total['percent']) . '%' }}</b>
            </div>
        @empty
            <div class="money-box__item money-box__item--main"><span>Occupancy</span><b>-</b></div>
        @endforelse
        <div class="money-box__item"><span>Dates · {{ $days }} {{ $days === 1 ? 'day' : 'days' }}</span><b>{{ $range }}</b></div>
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
            <thead>
                <tr>
                    <th>Space › Unit</th>
                    <th>Mode</th>
                    <th class="text-center">Capacity</th>
                    <th class="text-center">Reservations</th>
                    <th class="text-right" title="Seats in use: hourly = on average per business hour, full day = on average per day, period = subscriptions taking a seat">Avg occupied</th>
                    <th class="text-right">Avg free</th>
                    <th class="occupancy-table__percent">%</th>
                    <th class="text-center" title="Capacity − reservations active now (hourly) / today (days)">Free today</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($spaces as $space)
                    <tr class="occupancy-table__space"><td colspan="8">{{ $space['space'] }}</td></tr>
                    @foreach ($space['units'] as $row)
                        @include('reportmodule::Report.partials.occupancy-row', ['label' => $row['unit'], 'row' => $row, 'note' => null])
                    @endforeach
                    @foreach ($space['totals'] as $mode => $total)
                        @include('reportmodule::Report.partials.occupancy-row', ['label' => $space['space'] . ' total', 'row' => $total, 'class' => 'report-total', 'note' => null])
                    @endforeach
                @empty
                    <tr><td colspan="8" class="empty-state">No active units here.</td></tr>
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
