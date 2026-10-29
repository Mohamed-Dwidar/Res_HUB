{{--
    The list "Renewal" column (the Renewal filter, 2026-10-23; Reservation::scopeRenewalStatus()): the period's renewal date and
    what happened. $reservation = the period (renewedBy eager loaded), $refDate = the last day of the filter's range: the row is
    the period covering it (user 2026-10-29, Reservation::shownOn() — the other columns already show the renewal when it has come):
    Left (+ Undo) | a renewal whose renewal date has come → "Renewal of #X" + Due / Pay (its own) or ✓ Paid |
    a renewal made in advance (renewal date after $refDate) → not mentioned, no Renew; Pay = the period's own debt first, else the
    renewal's | no renewal → Not renewed + Renew (pay first: Reservation::canRenew(), the owner may still) / Pay, Mark left.
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $renewal = $reservation->renewedBy;
    $renewsOn = $reservation->renewalDate();
    $renewalCame = $renewal && $renewsOn && $refDate && $renewsOn->lte($refDate);
    $owes = fn ($row) => $row && $row->canTakePayments() && $row->remaining > 0;
    $payUrl = fn ($row) => route($area . '.payments.create', ['payable' => 'reservation', 'payable_id' => $row->id]);
    $canRenew = $reservation->canRenew($area === 'account');
@endphp
<div class="whitespace-nowrap">{{ $renewsOn?->format($dateFormat) ?? '-' }}</div>
<div class="renewal-cell">
    @if ($reservation->left_at)
        <span class="badge badge-status-slate">Left</span>
        @include('reservationmodule::Reservation.partials.left-form', ['undo' => true])
    @elseif ($renewalCame)
        <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="text-xs text-slate-500">Renewal of #{{ $reservation->id }}</a>
        @if ($owes($renewal))
            <span class="text-danger font-medium">Due {{ number_format($renewal->remaining, 2) }}</span>
            <a href="{{ $payUrl($renewal) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
        @else
            <span class="text-success font-medium"><i data-lucide="check" class="inline h-4 w-4"></i> Paid</span>
        @endif
    @elseif ($renewal)
        {{-- renewed in advance: not mentioned, no Renew; Pay its own debt first, else the renewal's --}}
        @if ($owes($reservation))
            <a href="{{ $payUrl($reservation) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
        @elseif ($owes($renewal))
            <a href="{{ $payUrl($renewal) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
        @endif
    @else
        <span class="text-danger font-medium">Not renewed</span>
        @if ($canRenew)
            <a href="{{ route($area . '.reservations.create', ['renew' => $reservation->id]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="refresh-cw"></i> Renew</a>
        @endif
        @if ($owes($reservation))
            <a href="{{ $payUrl($reservation) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="wallet"></i> Pay</a>
            @unless ($canRenew)
                <span class="text-xs text-slate-500">{{ \Modules\ReservationModule\app\Models\Reservation::RENEW_PAY_FIRST }}</span>
            @endunless
        @endif
        @include('reservationmodule::Reservation.partials.left-form')
    @endif
</div>
