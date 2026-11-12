{{-- the totals over the filters (json html "income-totals"; the page renders it with zeros first): Total (main), Cash In, Refunds,
     Withholding, Tax — always shown, 0.00 too; with a comparison ($cmp = its totals, $cmpRange) each one's change + "vs <dates>" --}}
@php
    $value = fn ($field) => number_format((float) ($totals->$field ?? 0), 2);
    $cmp ??= null;
    $change = fn ($field, $up = true) => \Modules\ReportModule\app\Services\ReportPeriod::change($totals->$field ?? 0, $cmp->$field ?? 0, $up);
@endphp
<div class="money-box">
    @foreach (['total' => ['Total', 'money-box__item--main money-box__item--due', true], 'cash_in' => ['Cash In', 'money-box__item--paid', true],
        'refunds' => ['Refunds', 'money-box__item--owed', false], 'withholding' => ['Withholding', 'money-box__item--credit', true], 'tax' => ['Tax', '', true]] as $field => [$label, $class, $up])
        <div class="money-box__item {{ $class }}"><span>{{ $label }}</span><b>{{ $value($field) }}</b>
            @if ($cmp)@include('reportmodule::Report.partials.change', ['change' => $change($field, $up)])@endif
        </div>
    @endforeach
    @if ($cmp)
        <div class="money-box__item"><span>{{ $range ?? '' }}</span><b class="text-slate-500 text-sm">vs {{ $cmpRange }}</b></div>
    @endif
</div>
