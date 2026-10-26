{{--
    One period row ($reservation, $kind today | overdue | next | new). Payment is due at the START of a period (2026-10-19):
    renewed = its renewal (renewedBy) exists AND is paid.
    - today / next: its renewal date (the day after its last day, Reservation::renewalDate()), the period's amount (net);
      no renewal → Renew (+ Mark left on Renews today, 2026-10-26) | a renewal still owing → "Renewal created · Due <remaining>" + Pay (Cash In for the renewal) |
      a paid renewal → ✓ Renewed → its end
    - overdue, overdue_kind renew (ended, no renewal): "due since <renewal date>", the period's amount, Renew + Mark left
      (+ its own Due / Pay when it still owes) | overdue_kind pay (the chain's current period still owing): "due since <its start>",
      its remaining, Pay
    - new (Starts today): → its end date, Paid / Due <remaining> (pay-badge), View
--}}
@php
    $renewsOn = $reservation->renewalDate();
    $days = (int) $date->diffInDays($renewsOn, false);
    $renewal = $reservation->renewedBy;
    $owes = fn ($row) => $row && $row->canTakePayments() && $row->remaining > 0;
    $renewalOwes = $owes($renewal);
    $payKind = $kind === 'overdue' && $reservation->overdue_kind === 'pay';
    $dueSince = $kind === 'overdue' ? \Illuminate\Support\Carbon::parse($reservation->due_since) : null;
    $dueDays = $dueSince ? (int) $dueSince->diffInDays($date, false) : 0;
    $payUrl = fn ($row) => route($area . '.payments.create', ['payable' => 'reservation', 'payable_id' => $row->id]);
@endphp
<tr>
    <td>
        <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="font-medium">{{ $reservation->member?->name ?? '-' }}</a>
        @if ($reservation->member?->phone)
            <div class="text-xs text-slate-500">{{ $reservation->member->phone }}</div>
        @endif
    </td>
    <td>
        {{ $reservation->unit?->name ?? '-' }}
        <div class="text-xs text-slate-500">{{ $reservation->space?->name }}</div>
    </td>
    <td class="whitespace-nowrap">
        @if ($kind === 'new')
            → {{ $reservation->realEnd()?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}
        @elseif ($kind === 'today')
            renews {{ $isToday ? 'today' : 'this day' }}
        @elseif ($kind === 'overdue')
            due since {{ $dueSince->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}
            @if ($dueDays > 0)
                <span class="text-danger">({{ $dueDays }} {{ Str::plural('day', $dueDays) }})</span>
            @endif
        @elseif ($days === 1 && $isToday)
            renews tomorrow
        @else
            renews {{ $renewsOn->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }} <span class="text-slate-500">(in {{ $days }} {{ Str::plural('day', $days) }})</span>
        @endif
    </td>
    @if ($kind === 'new')
        {{-- a new subscription: what is still owed (Due <remaining>), else its payment badge (Paid / In package / Credit) --}}
        <td class="today-renewals__amount">
            @if ($owes($reservation))
                <span class="text-danger">Due {{ number_format($reservation->remaining, 2) }}</span>
            @else
                @include('reservationmodule::Schedule.partials.pay-badge')
            @endif
        </td>
    @elseif ($payKind)
        {{-- the current period still owing: its remaining (partial payments: what is left) --}}
        <td class="today-renewals__amount text-danger">{{ number_format($reservation->remaining, 2) }}</td>
    @else
        {{-- the period's net amount (a renewal starts from the same amount); muted once renewed and paid; + its own debt --}}
        <td class="today-renewals__amount {{ $renewal && !$renewalOwes ? 'text-slate-400' : '' }}">
            {{ number_format($reservation->net_amount, 2) }}
            @if ($kind === 'overdue' && $owes($reservation))
                <div class="text-xs text-danger">Due {{ number_format($reservation->remaining, 2) }}</div>
            @endif
        </td>
    @endif
    <td class="today-renewals__actions">
        @if ($kind === 'new')
            <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="eye"></i> View</a>
        @elseif ($payKind)
            <a href="{{ $payUrl($reservation) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
        @elseif ($renewal && $renewalOwes)
            {{-- the renewal exists but is not paid: not renewed yet (payment due at its start) --}}
            <a href="{{ route($area . '.reservations.show', $renewal->id) }}" data-modal class="text-warning font-medium">Renewal created · Due {{ number_format($renewal->remaining, 2) }}</a>
            <a href="{{ $payUrl($renewal) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
        @elseif ($renewal)
            <a href="{{ route($area . '.reservations.show', $renewal->id) }}" data-modal class="text-success font-medium"><i data-lucide="check" class="inline h-4 w-4"></i> Renewed → {{ $renewal->realEnd()?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}</a>
        @else
            <a href="{{ route($area . '.reservations.create', ['renew' => $reservation->id]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="refresh-cw"></i> Renew</a>
            @if ($kind === 'overdue')
                @if ($owes($reservation))
                    <a href="{{ $payUrl($reservation) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="wallet"></i> Pay</a>
                @endif
                @include('reservationmodule::Reservation.partials.left-form')
            @elseif ($kind === 'today')
                @include('reservationmodule::Reservation.partials.left-form')
            @endif
        @endif
    </td>
</tr>
