{{--
    The action buttons of a renewal row ($view = RenewalView::make(), $area): [type, reservation, button class] —
    pay (Cash In for that period), renew (the Renew form; an unpaid period: it shows the total owed and asks a reason),
    left / undo (Mark left with the text "Left" / Undo left).
--}}
@foreach ($view['actions'] as [$action, $row, $class])
    @switch($action)
        @case('pay')
            <a href="{{ route($area . '.payments.create', ['payable' => 'reservation', 'payable_id' => $row->id]) }}" data-modal class="btn {{ $class }} btn-sm renewal-btn"><i data-lucide="wallet"></i> Pay</a>
            @break
        @case('renew')
            <a href="{{ route($area . '.reservations.create', ['renew' => $row->id]) }}" data-modal class="btn {{ $class }} btn-sm renewal-btn"><i data-lucide="refresh-cw"></i> Renew</a>
            @break
        @case('left')
            @include('reservationmodule::Reservation.partials.left-form', ['reservation' => $row, 'label' => 'Left', 'class' => 'btn btn-secondary btn-sm renewal-btn'])
            @break
        @case('undo')
            @include('reservationmodule::Reservation.partials.left-form', ['reservation' => $row, 'undo' => true, 'class' => 'btn btn-secondary btn-sm renewal-btn'])
            @break
    @endswitch
@endforeach
