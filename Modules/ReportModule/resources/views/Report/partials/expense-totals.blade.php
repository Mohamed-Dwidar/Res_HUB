{{-- the totals over the filters (json html "expense-totals"; the page renders it with zeros first): Total (main), Shift Cash Out,
     Company Cash Out, Count — always shown --}}
@php($money = fn ($field) => number_format((float) ($totals->$field ?? 0), 2))
<div class="money-box">
    <div class="money-box__item money-box__item--main"><span>Total Cash Out</span><b>{{ $money('total') }}</b></div>
    <div class="money-box__item"><span>Shift Cash Out</span><b>{{ $money('shift') }}</b></div>
    <div class="money-box__item"><span>Company Cash Out</span><b>{{ $money('company') }}</b></div>
    <div class="money-box__item"><span>Count</span><b>{{ (int) ($totals->count ?? 0) }}</b></div>
</div>
