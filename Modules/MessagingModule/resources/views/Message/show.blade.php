@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@php($format = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT . ' ' . \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT)
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{--
    The WhatsApp popup (MessagingService::popup()): the member, one row per enabled type (★ = the suggested one, first) with its
    Send (the wa.me link opens WhatsApp in a new tab; custom.js logs it beside: [data-message-send]) and its last send, the history.
    AR | EN: the language of the links (the member's WhatsApp language / the account default first; a type with no English text
    sends Arabic). No message text is stored. Back: the previous popup page (custom.js [data-message-back]: the back history),
    else the reservation view (opened from a list / the Schedule, after saving a reservation / Mark left).
--}}
@section('title')
    <span class="reservation-title">
        <img src="{{ asset('assets/images/whatsapp.svg') }}" alt="" class="wa-title-icon">
        <div>
            <div class="text-lg font-medium">{{ $reservation->member?->name ?? '-' }}</div>
            <div class="text-xs text-slate-500">{{ $reservation->member?->phone }}@if ($number) · WhatsApp +{{ $number }}@endif</div>
        </div>
    </span>
@endsection

@section('modal-size', 'lg')

@section('actions')
    <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal data-message-back class="btn btn-secondary btn-sm mr-2"><i data-lucide="arrow-left"></i> Back</a>
    @if ($number)
        <div class="message-lang" data-message-lang="{{ $language }}">
            <button type="button" data-message-lang-set="ar" @class(['message-lang__btn', 'is-active' => $language === 'ar'])>AR</button>
            <button type="button" data-message-lang-set="en" @class(['message-lang__btn', 'is-active' => $language === 'en'])>EN</button>
        </div>
    @endif
@endsection

@section('content')
    <div class="message-popup" data-message-popup>
        <div class="mb-4 text-slate-600">
            {{ $reservation->unit?->name ?? '-' }} · {{ $reservation->space?->name }} · {{ $reservation->period }}
            <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="text-xs">#{{ $reservation->id }}</a>
        </div>

        @if ($error)
            <div class="notice mb-4">
                {{ $error }}
                @if ($reservation->member)
                    <a href="{{ route($area . '.members.edit', $reservation->member_id) }}" data-modal class="font-medium">Edit the member's phone</a>
                @endif
            </div>
        @endif

        @if (empty($rows))
            <div class="text-slate-500">Every message type is switched off for this space (Settings › WhatsApp Messages).</div>
        @else
            <div class="table-wrap">
                <table class="data-table message-types">
                    <thead><tr><th>Message</th><th>Last sent</th><th class="text-center">Send</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr @class(['message-types__suggested' => $row['suggested']])>
                                <td>
                                    <div class="font-medium">@if ($row['suggested'])<span class="message-types__star" title="Suggested">★</span> @endif{{ $row['label'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $row['hint'] }}@unless ($row['hasEnglish']) · Arabic only @endunless</div>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($row['last'])
                                        {{ $row['last']->created_at?->format($format) }}
                                        <div class="text-xs text-slate-500">{{ $row['last']->creatable?->userable?->displayName() ?? '-' }}</div>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($row['urls'])
                                        <a href="{{ $row['urls'][$language] }}" target="_blank" rel="noopener" class="btn btn-sm btn-whatsapp"
                                            data-message-send="{{ route($area . '.messages.send', ['reservation', $reservation->id]) }}"
                                            data-type="{{ $row['type'] }}" data-context="{{ $context }}"
                                            data-suggested="{{ collect($rows)->firstWhere('suggested')['type'] ?? '' }}"
                                            data-href-ar="{{ $row['urls']['ar'] }}" data-href-en="{{ $row['urls']['en'] }}"
                                            data-has-en="{{ $row['hasEnglish'] ? 1 : 0 }}">
                                            <img src="{{ asset('assets/images/whatsapp.svg') }}" alt=""> Send
                                        </a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @include('messagingmodule::Message.partials.history')
    </div>
@endsection
