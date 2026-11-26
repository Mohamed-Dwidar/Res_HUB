{{--
    Who / when a reservation was cancelled (2026-11-26): reservations.cancelled_at / cancelled_by (ReservationService::changeStatus());
    older cancellations were never dated. Used by the view, the reservations list Status cell and the Schedule Cancelled popup.
    $inline = one line (list / popup), else "by <b>X</b> | At …" after the status name (the view).
--}}
@php($cancelBy = $reservation->cancelledBy?->userable?->displayName() ?? $reservation->cancelledBy?->email ?? '-')
@if ($inline ?? false)
    @if ($reservation->cancelled_at)
        <div class="text-xs text-slate-500">by {{ $cancelBy }}</div>
        <div class="text-xs text-slate-500 whitespace-nowrap">{{ $reservation->cancelled_at->format('d-m-Y h:i A') }}</div>
    @else
        <div class="text-xs text-slate-500">date not recorded</div>
    @endif
@else
    @if ($reservation->cancelled_at)
        by <b>{{ $cancelBy }}</b> | At {{ $reservation->cancelled_at->format('d-m-Y h:i A') }}
    @else
        <span class="text-slate-500">(date not recorded)</span>
    @endif
@endif
