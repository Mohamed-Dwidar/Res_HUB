{{--
    One row of a period group ($reservation, $kind new | today | next | owing; ScheduleService::periodGroups()), user 2026-10-29:
    Member | Unit | Renewal date | Price | Payment | Action — the texts / badges / buttons from RenewalView::make() (shared with the
    reservations list Renewal column). data-space: the "Not paid by space" chips filter the section's table.
--}}
@php($view = \Modules\ReservationModule\app\Services\RenewalView::make($reservation, $kind, $date, $isToday))
@php($open = $view['open'])
<tr class="renewal-row" data-space="{{ $reservation->space_id }}">
    <td data-label="Member">
        <a href="{{ route($area . '.reservations.show', $open->id) }}" data-modal class="font-medium">{{ $open->member?->name ?? '-' }}</a>
        @if ($open->member?->phone)
            <div class="text-xs text-slate-500">{{ $open->member->phone }}</div>
        @endif
    </td>
    <td data-label="Unit">
        {{ $open->unit?->name ?? '-' }}
        <div class="text-xs text-slate-500">{{ $open->space?->name }}</div>
        @include('contractmodule::Contract.partials.badge', ['reservation' => $open])
    </td>
    @php($statusCol = in_array($kind, ['owing', 'not_renewed', 'not_paid'], true))
    <td data-label="{{ $statusCol && $kind !== 'not_renewed' ? 'Date' : 'Renewal date' }}">
        @if ($view['datePrefix'])<div class="text-xs text-danger">{{ $view['datePrefix'] }}</div>@endif
        @if ($view['period'])
            {{-- renewal rows (2026-11-19, user): renewal date + when on one line, the renewal period under it --}}
            <div class="renewal-row__when-line"><span class="whitespace-nowrap">{{ $view['date']?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}</span>@if ($view['when']) <span class="text-xs whitespace-nowrap {{ $view['whenClass'] }}">{{ str_starts_with($view['when'], 'due since') ? $view['when'] : '(' . $view['when'] . ')' }}</span>@endif</div>
            <div class="text-xs text-slate-500 whitespace-nowrap">{{ $view['period'] }}</div>
        @else
        <div><span class="whitespace-nowrap">{{ $view['date']?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}</span>@if ($view['dateTo']) <span class="whitespace-nowrap">→ {{ $view['dateTo']->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}</span>@endif</div>
        @if ($view['when'])<div class="text-xs whitespace-nowrap {{ $view['whenClass'] }}">{{ $view['when'] }}</div>@endif
        @endif
    </td>
    <td data-label="{{ in_array($kind, ['today', 'next', 'not_renewed'], true) ? 'Renewal price' : 'Price' }}" class="renewal-row__price">{{ number_format($view['price'], 2) }}
        @if ($view['current'])<div class="renewal-row__current">{{ $view['current'] }}</div>@endif
        @if ($view['nextPrice'])<div class="renewal-next-price" title="The next renewal's price (the renewal increase)">Renewal: {{ $view['nextPrice'] }}</div>@endif</td>
    <td data-label="{{ $statusCol ? 'Status' : 'Payment' }}" @if ($statusCol) class="renewal-row__status" @endif>@include('reservationmodule::Reservation.partials.renewal-payment')</td>
    {{-- + WhatsApp after the last button (MessagingModule): about the period the row shows (the renewal when the row shows it:
         its sends = the ✓; suggested = Payment due while it owes, else by its state), else this period with the group's suggestion --}}
    <td data-label="" class="renewal-row__action">@include('reservationmodule::Reservation.partials.renewal-actions')@include('messagingmodule::Message.partials.button', $open->id === $reservation->id
        ? ['messageContext' => $statusCol ? 'owing' : $kind, 'messageDate' => $date]
        : ['reservation' => $open, 'messageContext' => $view['due'] ? 'owing' : null, 'messageDate' => $date])</td>
</tr>
@if (!empty($reservation->member_total) && in_array($kind, ['owing', 'not_paid'], true))
    {{-- a member with 2+ owing periods: their total under the last one --}}
    <tr class="renewal-row__total" data-spaces="{{ $reservation->member_spaces }}">
        <td colspan="6">Member total due: <b class="text-danger">{{ number_format($reservation->member_total, 2) }}</b> <span class="text-slate-500">({{ $open->member?->name }})</span></td>
    </tr>
@endif
