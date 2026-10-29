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
    </td>
    <td data-label="{{ $kind === 'owing' ? 'Date' : 'Renewal date' }}">
        @if ($view['datePrefix'])<div class="text-xs text-danger">{{ $view['datePrefix'] }}</div>@endif
        <div><span class="whitespace-nowrap">{{ $view['date']?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}</span>@if ($view['dateTo']) <span class="whitespace-nowrap">→ {{ $view['dateTo']->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}</span>@endif</div>
        @if ($view['when'])<div class="text-xs whitespace-nowrap {{ $view['whenClass'] }}">{{ $view['when'] }}</div>@endif
    </td>
    <td data-label="Price" class="renewal-row__price">{{ number_format($view['price'], 2) }}</td>
    <td data-label="{{ $kind === 'owing' ? 'Status' : 'Payment' }}" @if ($kind === 'owing') class="renewal-row__status" @endif>@include('reservationmodule::Reservation.partials.renewal-payment')</td>
    {{-- + WhatsApp after the last button (MessagingModule): about this period, the group = the suggested type --}}
    <td data-label="" class="renewal-row__action">@include('reservationmodule::Reservation.partials.renewal-actions')@include('messagingmodule::Message.partials.button', ['messageContext' => $kind, 'messageDate' => $date])</td>
</tr>
@if (!empty($reservation->member_total) && $kind === 'owing')
    {{-- a member with 2+ owing periods: their total under the last one --}}
    <tr class="renewal-row__total" data-spaces="{{ $reservation->member_spaces }}">
        <td colspan="6">Member total due: <b class="text-danger">{{ number_format($reservation->member_total, 2) }}</b> <span class="text-slate-500">({{ $open->member?->name }})</span></td>
    </tr>
@endif
