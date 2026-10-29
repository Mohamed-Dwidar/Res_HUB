{{--
    The list "Renewal" column (the Renewal filter, 2026-10-29): the Schedule renewal row in one cell — the date (+ due since /
    in N days), the price, the Payment cell and the buttons, from RenewalView::make() ($kind owing | upcoming | renewed | left,
    $asOf = the range's last day, the business date at most). $reservation = the period (renewedBy eager loaded).
--}}
@php($view = \Modules\ReservationModule\app\Services\RenewalView::make($reservation, $kind, $asOf, $asOf->eq(\Modules\ReservationModule\app\Models\Reservation::businessToday()[0])))
<div class="renewal-cell">
    <div>
        @if ($view['datePrefix'])<div class="text-xs text-danger">{{ $view['datePrefix'] }}</div>@endif
        <div><span class="whitespace-nowrap">{{ $view['date']?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}</span>@if ($view['dateTo']) <span class="whitespace-nowrap">→ {{ $view['dateTo']->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}</span>@endif</div>
        @if ($view['when'])<div class="text-xs whitespace-nowrap {{ $view['whenClass'] }}">{{ $view['when'] }}</div>@endif
        <div class="renewal-row__price">{{ number_format($view['price'], 2) }}</div>
    </div>
    <div>@include('reservationmodule::Reservation.partials.renewal-payment')</div>
    @if ($view['actions'])
        <div class="renewal-cell__action">@include('reservationmodule::Reservation.partials.renewal-actions')</div>
    @endif
</div>
