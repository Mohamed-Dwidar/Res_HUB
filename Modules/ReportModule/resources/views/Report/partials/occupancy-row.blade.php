{{-- one Occupancy row (a unit, a space subtotal or the overall total): capacity, reservations, avg occupied / free seats (period: the
     seats taken + "N renewal pending"), the % bar
     (<progress>, no inline CSS: booked ÷ available in the title), free today --}}
@php
    $num = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $suffix = ['hourly' => ' h', 'full_day' => ' days', 'period' => ' seats'][$row['mode']];
@endphp
<tr @class([$class ?? null])>
    <td>
        {{ $label }}@if ($note) <span class="text-xs text-slate-500">· {{ $note }}</span>@endif
        {{-- a unit inactive now that had reservations in the period (2026-11-18) --}}
        @if (!empty($row['inactive']))<span class="badge badge-status-slate badge-small">Inactive</span>@endif
        {{-- a subtotal: units without reservations in the period are left out of its % --}}
        @if (($row['excluded'] ?? 0) > 0)
            <div class="text-xs text-slate-500">{{ $row['excluded'] }} {{ $row['excluded'] === 1 ? 'unit' : 'units' }} without reservations not in the %</div>
        @endif
        @if (($row['pending'] ?? 0) > 0)
            <div class="text-xs text-pending">{{ $row['pending'] }} renewal pending</div>
        @endif
    </td>
    <td class="whitespace-nowrap">{{ \Modules\ReportModule\app\Services\OccupancyReport::MODES[$row['mode']] }}</td>
    <td class="text-center">{{ $row['capacity'] }}</td>
    <td class="text-center">{{ $row['reservations'] }}</td>
    <td class="text-right whitespace-nowrap">{{ $num($row['occupied']) }}</td>
    <td class="text-right whitespace-nowrap">{{ $num($row['free']) }}</td>
    <td class="occupancy-table__percent" title="{{ $num($row['booked']) . $suffix }} booked of {{ $num($row['available']) . $suffix }}@if (array_key_exists('cmp_percent', $row)) · compared: {{ $row['cmp_percent'] === null ? '-' : $num($row['cmp_percent']) . '%' }}@endif">
        @if ($row['percent'] === null)
            -
        @else
            <div class="occupancy-bar">
                <progress value="{{ min(100, $row['percent']) }}" max="100" @class(['occupancy-bar--high' => $row['percent'] >= 80, 'occupancy-bar--low' => $row['percent'] < 30])></progress>
                <span>{{ $num($row['percent']) }}%</span>
            </div>
        @endif
    </td>
    <td @class(['text-center', 'text-danger font-medium' => $row['free_today'] < 0])>{{ $row['free_today'] }}</td>
    {{-- the money (2026-11-18): the value of the bookings in the period (+ the comparison under Revenue) --}}
    <td class="text-right whitespace-nowrap">{{ number_format($row['standalone'] ?? 0, 2) }}</td>
    <td class="text-right whitespace-nowrap">{{ number_format($row['packaged'] ?? 0, 2) }}</td>
    <td class="text-right whitespace-nowrap font-medium">{{ number_format($row['revenue'] ?? 0, 2) }}
        @if (array_key_exists('cmp_revenue', $row))
            <div class="text-xs font-normal text-slate-500">{{ number_format($row['cmp_revenue'], 2) }} @include('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($row['revenue'], $row['cmp_revenue']), 'short' => true])</div>
        @endif
    </td>
</tr>
