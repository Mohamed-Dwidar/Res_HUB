{{--
    The WhatsApp icon of a reservation (MessagingModule; the Schedule rows, the reservations list row menu, the reservation view):
    opens the message popup. $reservation = the period the row shows (a Schedule row showing the renewal passes the renewal);
    $messageContext = the Schedule group / the list's Renewal filter (new | next | upcoming | today | owing | left), else the
    suggestion comes from the reservation's state; $messageDate = the Schedule day.
    Grey = nothing sent for this reservation yet; green + ✓ = any message sent for it (2026-10-31: not the other periods of its
    chain), tooltip "Last sent: name · date time · who"; disabled with the reason when the phone can't be used; nothing when the
    messages are off or the reservation's unit has no switched on message. [data-wa-reservation]: custom.js puts the ✓ on at once
    after a Send in the popup.
    $menu (an action of layoutmodule::partials.row-menu, the reservations list): a menu item "WhatsApp" + the last send / the
    suggested message under it.
--}}
@php($whatsapp = app(\Modules\MessagingModule\app\Services\MessagingService::class)->button($reservation, $messageContext ?? null, $messageDate ?? null))
@php($waIcon = asset('assets/images/whatsapp.svg'))
@if ($whatsapp && !empty($menu))
    @if ($whatsapp['error'])
        <span class="{{ config('layoutmodule.menu.item') }} wa-menu-item wa-menu-item--disabled" title="{{ $whatsapp['error'] }}">
            <img src="{{ $waIcon }}" alt="" class="wa-menu-item__icon">
            <span>WhatsApp<span class="wa-menu-item__sub">No WhatsApp number</span></span>
        </span>
    @else
        <a href="{{ $whatsapp['url'] }}" data-modal data-tw-dismiss="dropdown" data-wa-reservation="{{ $reservation->id }}" title="{{ $whatsapp['sentTitle'] ?? 'Nothing sent yet' }}"
            @class([config('layoutmodule.menu.item'), 'wa-menu-item', 'wa-menu-item--sent' => $whatsapp['sent']])>
            <img src="{{ $waIcon }}" alt="" class="wa-menu-item__icon">
            <span>WhatsApp<span class="wa-btn__check wa-menu-item__check" @unless ($whatsapp['sent']) hidden @endunless>✓</span>
                <span class="wa-menu-item__sub" data-wa-sub>{{ $whatsapp['sent'] ? 'Sent ' . $whatsapp['sent']->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) : ($whatsapp['label'] ?? '') }}</span>
            </span>
        </a>
    @endif
@elseif ($whatsapp)
    @if ($whatsapp['error'])
        <span class="wa-btn wa-btn--disabled" title="{{ $whatsapp['error'] }}"><img src="{{ $waIcon }}" alt="WhatsApp"></span>
    @else
        <a href="{{ $whatsapp['url'] }}" data-modal data-wa-reservation="{{ $reservation->id }}" @class(['wa-btn', 'wa-btn--sent' => $whatsapp['sent']])
            title="{{ $whatsapp['sentTitle'] ?? 'WhatsApp · nothing sent yet' . ($whatsapp['label'] ? ' (suggested: ' . $whatsapp['label'] . ')' : '') }}"><img src="{{ $waIcon }}" alt="WhatsApp"><span class="wa-btn__check" @unless ($whatsapp['sent']) hidden @endunless>✓</span></a>
    @endif
@endif
