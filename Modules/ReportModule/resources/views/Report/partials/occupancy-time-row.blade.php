{{-- one Over time row (a period or the total): each cell = this value + a grey line with last year's value and the change
     ($booked / $percent / $num from occupancy-time; $note = a small line under the label (Average); the % change = percentage points shown as the change of the % itself) --}}
@php
    $cmp = $row['cmp'];
    $compared = function ($text, $current, $previous) {
        return '<div class="text-xs font-normal text-slate-500">' . e($text) . ' '
            . view('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($current ?? 0, $previous ?? 0), 'short' => true])->render() . '</div>';
    };
@endphp
<tr @class([$class ?? null]) title="{{ $title }}">
    <td class="whitespace-nowrap">{{ $label }}
        {{-- the running (to-date) period: not in the Average row --}}
        @if (!empty($row['running']))<span class="badge badge-status-slate badge-small">To date · not in the average</span>@endif
        @if (!empty($note))<div class="text-xs font-normal text-slate-500">{{ $note }}</div>@endif
    </td>
    <td class="text-center">{{ $row['reservations'] }}{!! $compared($cmp['reservations'], $row['reservations'], $cmp['reservations']) !!}</td>
    <td class="text-right whitespace-nowrap">{{ $booked($row['booked']) }}{!! $compared($booked($cmp['booked']), array_sum($row['booked']), array_sum($cmp['booked'])) !!}</td>
    <td class="text-right whitespace-nowrap">{{ $percent($row['percent']) }}
        @if ($row['percent'] !== null && $cmp['percent'] !== null){!! $compared($percent($cmp['percent']), $row['percent'], $cmp['percent']) !!}@else<div class="text-xs font-normal text-slate-500">{{ $percent($cmp['percent']) }}</div>@endif
    </td>
    @foreach (['standalone', 'packaged', 'revenue'] as $key)
        <td @class(['text-right whitespace-nowrap', 'font-medium' => $key === 'revenue'])>{{ number_format($row[$key], 2) }}{!! $compared(number_format($cmp[$key], 2), $row[$key], $cmp[$key]) !!}</td>
    @endforeach
</tr>
