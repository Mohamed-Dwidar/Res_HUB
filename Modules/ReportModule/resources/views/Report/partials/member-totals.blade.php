{{-- the totals of the chosen tab (json html "member-totals"; zeros first): Members (main), Paid, Balance (member tabs) /
     New members (By occupation), Dates --}}
@php($money = fn ($field) => number_format((float) ($totals->$field ?? 0), 2))
<div class="money-box">
    <div class="money-box__item money-box__item--main"><span>{{ \Modules\ReportModule\app\Services\MemberReport::TABS[$filters['view']] }}</span><b>{{ (int) ($totals->members ?? 0) }} members</b></div>
    @if ($filters['view'] === 'occupation')
        <div class="money-box__item"><span>New members</span><b>{{ (int) ($totals->new_members ?? 0) }}</b></div>
    @endif
    <div class="money-box__item money-box__item--paid"><span>Paid</span><b>{{ $money('paid') }}</b></div>
    @if ($filters['view'] !== 'occupation')
        <div class="money-box__item money-box__item--owed"><span>Balance{{ !empty($balanceDate) ? ' on ' . $balanceDate->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) : '' }}</span><b>{{ $money('balance') }}</b></div>
    @endif
    @if ($range)
        <div class="money-box__item"><span>Dates</span><b>{{ $range }}</b></div>
    @endif
</div>
