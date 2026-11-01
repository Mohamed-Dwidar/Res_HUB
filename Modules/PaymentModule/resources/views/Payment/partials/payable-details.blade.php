{{--
    The Add Cash In form opened for one reservation / package ($payable, $kind reservation | package): what is paid for, read only
    (the form sends it in hidden inputs): the member, the reservation (type, place, plan, period) or the package (period),
    then the money on two lines (Payment/partials/amount-lines): Amount - Discount - Net, Paid - Remaining.
--}}
@php
    $member = $payable->member;
@endphp
<div class="form-section">Cash In For</div>
<dl class="detail-list">
    <div><dt>Member</dt><dd>@include('membermodule::partials.member-link', ['member' => $member]) @if ($member?->phone)<span class="text-xs text-slate-500">{{ $member->phone }}</span>@endif</dd></div>
    @if ($kind === 'reservation')
        <div><dt>Reservation</dt><dd>#{{ $payable->id }} · {{ $payable->subscriptionType?->name ?? '-' }}</dd></div>
        <div><dt>Space &rsaquo; Unit</dt><dd>{{ $payable->space?->name ?? '-' }} &rsaquo; {{ $payable->unit?->name ?? '-' }}</dd></div>
        <div><dt>Plan</dt><dd>{{ $payable->plan?->name ?? '-' }}</dd></div>
        <div><dt>Period</dt><dd>{{ $payable->period }}</dd></div>
    @else
        <div><dt>Package</dt><dd>{{ $payable->name }}</dd></div>
        <div><dt>Period</dt><dd>{{ $payable->period }}</dd></div>
    @endif
</dl>

<div class="mt-5">
    @include('paymentmodule::Payment.partials.amount-lines', ['payable' => $payable, 'kind' => $kind])
</div>
