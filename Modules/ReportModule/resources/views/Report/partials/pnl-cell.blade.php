{{-- one Profit & Loss amount cell (2026-11-12): this period's amount (bold); with a comparison ($cmp not null) under it the
     compared amount (small, grey, with its $label) and the change badge (▲ / ▼ %, green / red; $upIsGood = false: a rise is red).
     $percent = a margin cell (values are %, no badge); $class = the amount's colour --}}
@php
    $upIsGood ??= true;
    $percent ??= false;
    $cmp ??= null;
    $hasCmp = $label !== null && ($percent ? true : $cmp !== null);
    $show = fn ($v) => $v === null ? '-' : ($percent ? $v . '%' : number_format((float) $v, 2));
@endphp
<td class="text-right">
    <div class="pnl-cell__value">
        @if ($percent)
            {{ $show($value) }}
        @else
            @include('reportmodule::Report.partials.money', ['value' => $value, 'class' => $class ?? ''])
        @endif
    </div>
    @if ($hasCmp ?? false)
        <div class="pnl-cell__cmp"><span class="pnl-cell__label">{{ $label }}</span> {{ $show($cmp) }}</div>
        @if (!$percent && (round((float) $value, 2) != 0 || round((float) $cmp, 2) != 0))
            <div>@include('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($value, $cmp, $upIsGood), 'short' => true, 'pill' => true])</div>
        @endif
    @endif
</td>
