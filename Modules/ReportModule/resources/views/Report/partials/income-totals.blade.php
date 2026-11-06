{{-- the totals over the filters (json html "income-totals"; the page renders it with zeros first): Total (main), Cash In, Refunds,
     Withholding, Tax — always shown, 0.00 too --}}
@php($value = fn ($field) => number_format((float) ($totals->$field ?? 0), 2))
<div class="money-box">
    <div class="money-box__item money-box__item--main money-box__item--due"><span>Total</span><b>{{ $value('total') }}</b></div>
    <div class="money-box__item money-box__item--paid"><span>Cash In</span><b>{{ $value('cash_in') }}</b></div>
    <div class="money-box__item money-box__item--owed"><span>Refunds</span><b>{{ $value('refunds') }}</b></div>
    <div class="money-box__item money-box__item--credit"><span>Withholding</span><b>{{ $value('withholding') }}</b></div>
    <div class="money-box__item"><span>Tax</span><b>{{ $value('tax') }}</b></div>
</div>
