{{-- the totals over the filters (json html "outstanding-totals") --}}
<div class="money-box">
    <div class="money-box__item money-box__item--owed"><span>Owed</span><b>{{ number_format($totals->owed, 2) }}</b></div>
    <div class="money-box__item money-box__item--credit"><span>Credit</span><b>{{ number_format($totals->credit, 2) }}</b></div>
    <div class="money-box__item money-box__item--due"><span>Net</span><b>{{ number_format($totals->net, 2) }}</b></div>
    <div class="money-box__item"><span>Members</span><b>{{ (int) $totals->members }}</b></div>
    <div class="money-box__item"><span>Periods</span><b>{{ (int) $totals->periods }}</b></div>
</div>
