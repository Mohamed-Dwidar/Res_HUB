@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
@endphp
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{-- a renewal (?renew=) has renewal_of; a reservation pre-filled from the Schedule page (type / unit / start) is a new one,
         named after its type (the type select is hidden there) --}}
    @if (!empty($reservation?->renewal_of))
        Renew Subscription
    @elseif ($reservation && $reservation->subscription_type_id && isset($subscriptionTypes[$reservation->subscription_type_id]))
        New Reservation · {{ $subscriptionTypes[$reservation->subscription_type_id]['name'] }}
    @else
        Add Reservation
    @endif
@endsection

@section('modal-size', 'xl')

@section('content')
    {{-- renewing a period that still owes (2026-10-29): the confirmation says what the member will owe across their periods --}}
    @php
        $renewedPeriod = !empty($reservation?->renewal_of) ? $reservation->renewalOf : null;
        $owing = $renewedPeriod && $renewedPeriod->renewalDebt() > 0
            ? \Modules\ReservationModule\app\Models\Reservation::owingPeriodsOf($renewedPeriod->account_id, $renewedPeriod->member_id) : null;
    @endphp
    <form method="POST" action="{{ route($area . '.reservations.store') }}" data-ajax data-reservation-form
        @if ($owing)
            data-confirm="#{{ $renewedPeriod->id }} is not fully paid. This member will owe {{ number_format($owing->sum('remaining') + (float) $reservation->net_amount, 2) }} across {{ $owing->count() + 1 }} periods."
            data-confirm-title="Renew before it is paid?"
            data-confirm-button="Renew"
            data-confirm-variant="danger"
        @else
            data-confirm="The reservation will be added."
            data-confirm-title="Add this reservation?"
            data-confirm-button="Add"
            data-confirm-variant="success"
        @endif
        >
        @csrf

        {{-- $reservation: a renewal (not saved, pre-filled from a subscription), pre-filled from the Schedule page, or null --}}
        @include('reservationmodule::Reservation.partials.form', ['reservation' => $reservation ?? null])

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.reservations.index')])
    </form>
@endsection
