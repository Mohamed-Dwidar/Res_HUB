{{--
    One period row ($reservation, $kind today | overdue | next | new). The row is the period covering the selected date (user
    2026-10-29, ScheduleService::periodGroups()): a renewal whose renewal date has come is the row itself ($reservation->renewal_row:
    the name opens it, its amount / remaining, Pay); Next N days = the current period, an advance renewal is not mentioned (no
    Renew), Pay = pay_target (its own debt first, else the renewal's). Renew = pay first (Reservation::canRenew(): the owner may
    still, the form asks a reason); an employee on an unpaid period gets Pay + "Pay this period first, then renew."
    Before 2026-10-29: payment is due at the START of a period (2026-10-19):
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
    $isRenewalRow = !empty($reservation->renewal_row);
    $payKind = ($kind === 'overdue' && $reservation->overdue_kind === 'pay') || $isRenewalRow;
    $isOwner = Auth::user()?->userable instanceof \Modules\AccountModule\app\Models\Account;
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
        @if ($isRenewalRow)
            <div class="text-xs text-slate-500">renewal of <a href="{{ route($area . '.reservations.show', $reservation->renewal_for) }}" data-modal>#{{ $reservation->renewal_for }}</a></div>
        @endif
    </td>
    <td>
        {{ $reservation->unit?->name ?? '-' }}
        <div class="text-xs text-slate-500">{{ $reservation->space?->name }}</div>
    </td>
    <td class="whitespace-nowrap">
        @if ($kind === 'new' || ($kind === 'today' && $isRenewalRow))
            {{-- a new subscription / a renewal starting on the day: its start → end (user 2026-10-29) --}}
            {{ $reservation->start_at?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}
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
        {{-- the period's amount (a renewal starts from the same amount) + its own debt --}}
        <td class="today-renewals__amount">
            {{ number_format($reservation->dueAmount(), 2) }}
            @if ($kind !== 'next' && $owes($reservation))
                <div class="text-xs text-danger">Due {{ number_format($reservation->remaining, 2) }}</div>
            @endif
        </td>
    @endif
    <td class="today-renewals__actions">
        @if ($kind === 'new')
            <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="eye"></i> View</a>
        @elseif ($payKind)
            <a href="{{ $payUrl($reservation) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
        @elseif ($kind === 'next')
            {{-- the current period: an advance renewal is not mentioned (no Renew); Pay = its own debt first, else the renewal's --}}
            @if ($reservation->pay_target)
                <a href="{{ $payUrl($reservation->pay_target) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="wallet"></i> Pay</a>
            @elseif (!$renewal)
                <a href="{{ route($area . '.reservations.create', ['renew' => $reservation->id]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="refresh-cw"></i> Renew</a>
            @endif
        @else
            {{-- no renewal yet: Renew (pay first; the owner may still), Pay its own debt, Mark left --}}
            @if ($reservation->canRenew($isOwner))
                <a href="{{ route($area . '.reservations.create', ['renew' => $reservation->id]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="refresh-cw"></i> Renew</a>
            @endif
            @if ($owes($reservation))
                <a href="{{ $payUrl($reservation) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="wallet"></i> Pay</a>
                @unless ($reservation->canRenew($isOwner))
                    <span class="text-xs text-slate-500">{{ \Modules\ReservationModule\app\Models\Reservation::RENEW_PAY_FIRST }}</span>
                @endunless
            @endif
            @if (in_array($kind, ['overdue', 'today'], true))
                @include('reservationmodule::Reservation.partials.left-form')
            @endif
        @endif
    </td>
</tr>
