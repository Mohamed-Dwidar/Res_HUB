{{-- the totals over the filters (json html "expense-totals"; the page renders it with zeros first): Total (main), Shift Cash Out,
     Company Cash Out, Count — always shown; with a comparison ($cmp, $cmpRange) each one's change (a rise = red) + "vs <dates>" --}}
@php
    $money = fn ($field) => number_format((float) ($totals->$field ?? 0), 2);
    $cmp ??= null;
    $change = fn ($field) => \Modules\ReportModule\app\Services\ReportPeriod::change($totals->$field ?? 0, $cmp->$field ?? 0, false);
@endphp
<div class="money-box">
    @foreach (['total' => ['Total Cash Out', 'money-box__item--main'], 'shift' => ['Shift Cash Out', ''], 'company' => ['Company Cash Out', '']] as $field => [$label, $class])
        <div class="money-box__item {{ $class }}"><span>{{ $label }}</span><b>{{ $money($field) }}</b>
            @if ($cmp)@include('reportmodule::Report.partials.change', ['change' => $change($field)])@endif
        </div>
    @endforeach
    <div class="money-box__item"><span>Count</span><b>{{ (int) ($totals->count ?? 0) }}</b></div>
    @if ($cmp)
        <div class="money-box__item"><span>{{ $range ?? '' }}</span><b class="text-slate-500 text-sm">vs {{ $cmpRange }}</b></div>
    @endif
</div>
