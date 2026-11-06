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
        @if (($row['pending'] ?? 0) > 0)
            <div class="text-xs text-pending">{{ $row['pending'] }} renewal pending</div>
        @endif
    </td>
    <td class="whitespace-nowrap">{{ \Modules\ReportModule\app\Services\OccupancyReport::MODES[$row['mode']] }}</td>
    <td class="text-center">{{ $row['capacity'] }}</td>
    <td class="text-center">{{ $row['reservations'] }}</td>
    <td class="text-right whitespace-nowrap">{{ $num($row['occupied']) }}</td>
    <td class="text-right whitespace-nowrap">{{ $num($row['free']) }}</td>
    <td class="occupancy-table__percent" title="{{ $num($row['booked']) . $suffix }} booked of {{ $num($row['available']) . $suffix }}">
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
</tr>
