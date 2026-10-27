@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{--
    The Schedule summary "Cancelled" (ScheduleActions::cancelled): the day's cancelled reservations (a not counted status) of the
    page's types — hourly: starting in the business day, full day / period: starting on the date (ScheduleService::cancelledQuery()).
    The member opens the reservation (Restore is in its view / the list row menu).
--}}
@section('title')
    Cancelled · {{ $date->format('D d-m-Y') }}
@endsection

@section('modal-size', 'xl')

@section('content')
    @if ($reservations->isEmpty())
        <div class="today-empty">No cancelled reservations on this day.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Member</th><th>Type</th><th>Space / Unit</th><th>Time</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($reservations as $reservation)
                        <tr>
                            <td>
                                <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="font-medium">{{ $reservation->member?->name ?? '-' }}</a>
                                @if ($reservation->member?->phone)
                                    <div class="text-xs text-slate-500">{{ $reservation->member->phone }}</div>
                                @endif
                            </td>
                            <td>{{ $reservation->subscriptionType?->name ?? '-' }}</td>
                            <td>
                                {{ $reservation->space?->name ?? '-' }}
                                <div class="text-xs text-slate-500">{{ $reservation->unit?->name }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($reservation->subscriptionType?->isHourly())
                                    {{ $reservation->start_at->format(\Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}
                                    @if ($reservation->end_at)
                                        → {{ $reservation->realEnd()->format(\Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}
                                    @endif
                                @else
                                    {{ $reservation->start_at->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}
                                    @if ($reservation->end_at && !$reservation->realEnd()->isSameDay($reservation->start_at))
                                        → {{ $reservation->realEnd()->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}
                                    @endif
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ number_format($reservation->dueAmount(), 2) }}</td>
                            <td><span class="{{ $reservation->status?->badge_class ?? 'badge badge-status-slate' }}">{{ $reservation->status?->name ?? '-' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
