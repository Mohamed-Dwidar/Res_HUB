{{--
    The WhatsApp icon of a reservation (MessagingModule; the Schedule rows, the reservations list Member column, the reservation
    view): opens the message popup. $reservation; $messageContext = the Schedule group / the list's Renewal filter (new | next |
    upcoming | today | owing | left), else the suggested type comes from the reservation's state; $messageDate = the Schedule day.
    Grey = the suggested type not sent yet, green + ✓ = sent (for this reservation or its renewal); disabled with the reason when
    the phone can't be used; nothing when the messages are off (Settings › WhatsApp Messages).
    $menu (an action of layoutmodule::partials.row-menu, the reservations list): a menu item "WhatsApp" + the suggested type under it.
--}}
@php($whatsapp = app(\Modules\MessagingModule\app\Services\MessagingService::class)->button($reservation, $messageContext ?? null, $messageDate ?? null))
@if ($whatsapp && !empty($menu))
    @if ($whatsapp['error'])
        <span class="{{ config('layoutmodule.menu.item') }} wa-menu-item wa-menu-item--disabled" title="{{ $whatsapp['error'] }}">
            <img src="{{ asset('assets/images/whatsapp.svg') }}" alt="" class="wa-menu-item__icon">
            <span>WhatsApp<span class="wa-menu-item__sub">No WhatsApp number</span></span>
        </span>
    @else
        <a href="{{ $whatsapp['url'] }}" data-modal data-tw-dismiss="dropdown" @class([config('layoutmodule.menu.item'), 'wa-menu-item', 'wa-menu-item--sent' => $whatsapp['sent']])>
            <img src="{{ asset('assets/images/whatsapp.svg') }}" alt="" class="wa-menu-item__icon">
            <span>WhatsApp @if ($whatsapp['sent'])<span class="wa-btn__check wa-menu-item__check">✓</span>@endif
                @if ($whatsapp['label'])<span class="wa-menu-item__sub">{{ $whatsapp['label'] }}{{ $whatsapp['sent'] ? ' · sent ' . $whatsapp['sent']->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) : '' }}</span>@endif
            </span>
        </a>
    @endif
@elseif ($whatsapp)
    @if ($whatsapp['error'])
        <span class="wa-btn wa-btn--disabled" title="{{ $whatsapp['error'] }}"><img src="{{ asset('assets/images/whatsapp.svg') }}" alt="WhatsApp"></span>
    @else
        <a href="{{ $whatsapp['url'] }}" data-modal @class(['wa-btn', 'wa-btn--sent' => $whatsapp['sent']])
            title="WhatsApp{{ $whatsapp['label'] ? ' · ' . $whatsapp['label'] . ($whatsapp['sent'] ? ' sent ' . $whatsapp['sent']->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT . ' ' . \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) : ' (not sent yet)') : '' }}"><img src="{{ asset('assets/images/whatsapp.svg') }}" alt="WhatsApp">@if ($whatsapp['sent'])<span class="wa-btn__check">✓</span>@endif</a>
    @endif
@endif
