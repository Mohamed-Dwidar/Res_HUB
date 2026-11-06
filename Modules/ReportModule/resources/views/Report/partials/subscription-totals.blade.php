{{-- the totals of the chosen tab (json html "subscription-totals"; zeros first): Subscriptions (main), Amount, Owed — always shown;
     Not renewed adds "Renewed · unpaid" --}}
@php($money = fn ($field) => number_format((float) ($totals->$field ?? 0), 2))
<div class="money-box">
    <div class="money-box__item money-box__item--main"><span>{{ \Modules\ReportModule\app\Services\SubscriptionReport::TABS[$filters['view']] }}</span><b>{{ (int) ($totals->count ?? 0) }}</b></div>
    @if ($filters['view'] === 'not_renewed')
        <div class="money-box__item"><span>Renewed · unpaid</span><b>{{ (int) ($counts['renewed_unpaid'] ?? 0) }}</b></div>
    @endif
    <div class="money-box__item money-box__item--due"><span>Amount</span><b>{{ $money('amount') }}</b></div>
    <div class="money-box__item money-box__item--owed"><span>Owed</span><b>{{ $money('owed') }}</b></div>
    @if ($range)
        <div class="money-box__item"><span>Dates</span><b>{{ $range }}</b></div>
    @endif
</div>
