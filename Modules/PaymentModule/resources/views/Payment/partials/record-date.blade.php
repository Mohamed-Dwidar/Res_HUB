{{--
    The date of a cash in / refund / cash out ($record: Payment | Expense) + the time it was recorded (time: true) + the
    "Late entry · dated <date>" badge when an employee recorded it late, or "Date changed by owner" (Concerns\LateEntry): the Payments / Cash Out lists,
    My Shift, the shift popups, the reservation / package Cash In block.
--}}
@php
    $date = $record instanceof \Modules\PaymentModule\app\Models\Expense ? $record->spent_at : $record->paid_at;
    $dateText = $date?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT);
@endphp
{{ $dateText ?? '-' }}
@if ($time ?? true)
    <div class="text-xs text-slate-500">{{ $record->created_at?->format(\Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}</div>
@endif
@if ($record->isDateChangedByOwner())
    {{-- 2026-11-08: the owner changed the date (Concerns\LateEntry; not a late entry) --}}
    <div><span class="badge badge-status-info late-entry"
        title="Changed {{ $record->date_changed_at->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT . ' ' . \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}">Date changed by owner</span></div>
@elseif ($record->isLateEntry())
    <div><span class="badge badge-status-warning late-entry"
        title="Recorded {{ $record->created_at->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT . ' ' . \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT) }}">Late entry · dated {{ $dateText }}</span></div>
@endif
