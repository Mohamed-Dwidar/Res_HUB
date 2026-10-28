{{-- A full day section ($section, ScheduleService::fullDaySection()): the day's reservations by their date, by check-in time;
     columns (user 2026-10-28) Name | Space / Unit | Check In | Amount (green, what it costs: with taxes when invoiced) |
     Remaining (red when owed) | Status --}}
@php($type = $section['type'])
<section id="{{ $section['anchor'] }}" class="today-section">
    <div class="today-section__head">
        <div class="today-section__title">{{ $type->name }} <span class="today-section__count">{{ $section['count'] }}</span></div>
        <div class="today-section__tools">
            <a href="{{ route($area . '.reservations.create', ['subscription_type_id' => $type->id, 'start' => $date->toDateString()]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> {{ $type->name }}</a>
        </div>
    </div>
    @if ($section['rows']->isEmpty())
        <div class="today-empty">No {{ $type->name }} {{ $isToday ? 'today' : 'on this day' }}.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Space / Unit</th><th>Check In</th><th>Amount</th><th>Remaining</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($section['rows'] as $reservation)
                        <tr>
                            <td><a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="font-medium">{{ $reservation->member?->name ?? '-' }}</a></td>
                            <td>
                                {{ $reservation->space?->name ?? '-' }}
                                <div class="text-xs text-slate-500">{{ $reservation->unit?->name }}</div>
                            </td>
                            <td class="whitespace-nowrap">{{ $reservation->today_in?->format(\Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}</td>
                            <td class="whitespace-nowrap">@include('reservationmodule::Reservation.partials.row-amount')</td>
                            <td class="whitespace-nowrap">@include('paymentmodule::Payment.partials.remaining-amount', ['payable' => $reservation])</td>
                            <td>@include('reservationmodule::Schedule.partials.pay-badge')</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
