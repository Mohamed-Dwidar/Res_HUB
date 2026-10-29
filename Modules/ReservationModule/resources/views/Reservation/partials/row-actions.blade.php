@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'View', 'icon' => 'eye', 'url' => route($area . '.reservations.show', $reservation->id), 'modal' => true],
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.reservations.edit', $reservation->id), 'modal' => true],
        // Add Cash In: not inside a package (paid through the package), still owed (never more than the remaining, 2026-10-21)
        // not counted (cancelled, pending ...): back to the default counted status (popup, the slot is checked)
        // a counted period of a period type (subscriptions / contracts) not renewed yet: the next period, pre-filled (renewal_of);
        // pay first (2026-10-29, Reservation::canRenew(): the owner — the account area — may still, the form asks a reason)
        ...($reservation->canRenew($area === 'account') ? [['label' => 'Renew', 'icon' => 'refresh-cw', 'url' => route($area . '.reservations.create', ['renew' => $reservation->id]), 'modal' => true]] : []),
        ...(!$reservation->isCounted() ? [['label' => 'Restore', 'icon' => 'rotate-ccw', 'url' => route($area . '.reservations.restore', $reservation->id), 'modal' => true]] : []),
        ...($reservation->canTakePayments() && $reservation->remaining > 0 ? [['label' => 'Add Cash In', 'icon' => 'wallet', 'url' => route($area . '.payments.create', ['payable' => 'reservation', 'payable_id' => $reservation->id]), 'modal' => true]] : []),
    ],
    'actions' => [['view' => 'reservationmodule::Reservation.partials.delete-form', 'data' => ['reservation' => $reservation]]],
])
