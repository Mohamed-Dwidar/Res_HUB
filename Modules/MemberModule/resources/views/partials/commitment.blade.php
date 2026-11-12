{{-- the member's commitment badge (Members list column, member view next to the phone; 2026-11-12): not cancelled ÷ all reservations,
     all time — the Members report rule and colors (green ≥ 90%, yellow 70–89%, red < 70%); no reservations = nothing.
     $total = all reservations, $cancelled = the not counted ones; $withText = the counts written after the badge (member view) --}}
@if ($total > 0)
    @php($percent = (int) round(($total - $cancelled) * 100 / $total))
    <span @class(['badge', 'badge-status-success' => $percent >= 90, 'badge-status-warning' => $percent >= 70 && $percent < 90,
        'badge-status-danger' => $percent < 70]) title="{{ $total }} {{ Str::plural('reservation', $total) }} · {{ $cancelled }} cancelled">{{ $percent }}%</span>
    @if (!empty($withText))
        <span class="whitespace-nowrap">{{ $total }} {{ Str::plural('reservation', $total) }} · {{ $cancelled }} cancelled</span>
    @endif
@endif
