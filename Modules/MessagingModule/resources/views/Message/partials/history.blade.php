{{-- The popup's history ($history, MessagingService::history()): every send for the reservation, the period it renews and its renewal --}}
<div class="form-section mt-6">History</div>
@if ($history->isEmpty())
    <div class="text-slate-500">Nothing sent yet.</div>
@else
    <div class="table-wrap">
        <table class="data-table message-history">
            <thead><tr><th>Message</th><th>Period</th><th>Sent</th><th>By</th></tr></thead>
            <tbody>
                @foreach ($history as $log)
                    <tr>
                        <td>{{ $log->type_label }} <span class="text-xs text-slate-500">{{ strtoupper($log->language) }}</span></td>
                        <td class="whitespace-nowrap">
                            @if ((int) $log->messageable_id === (int) $reservation->id)
                                This period
                            @elseif ((int) $log->messageable_id === (int) $reservation->renewal_of)
                                Previous period
                            @else
                                The renewal
                            @endif
                            <span class="text-xs text-slate-500">#{{ $log->messageable_id }}</span>
                        </td>
                        <td class="whitespace-nowrap">{{ $log->created_at?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT . ' ' . \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}</td>
                        <td>{{ $log->creatable?->userable?->displayName() ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
