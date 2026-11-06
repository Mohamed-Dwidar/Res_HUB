{{-- the totals over the filters (json html "outstanding-totals"; the page renders it with zeros first): Owed (main), Credit, Net,
     Members, Periods — always shown --}}
@php($money = fn ($field) => number_format((float) ($totals->$field ?? 0), 2))
<div class="money-box">
    <div class="money-box__item money-box__item--main money-box__item--owed"><span>Owed</span><b>{{ $money('owed') }}</b></div>
    <div class="money-box__item money-box__item--credit"><span>Credit</span><b>{{ $money('credit') }}</b></div>
    <div class="money-box__item money-box__item--due"><span>Net</span><b>{{ $money('net') }}</b></div>
    <div class="money-box__item"><span>Members</span><b>{{ (int) ($totals->members ?? 0) }}</b></div>
    <div class="money-box__item"><span>Periods</span><b>{{ (int) ($totals->periods ?? 0) }}</b></div>
</div>
