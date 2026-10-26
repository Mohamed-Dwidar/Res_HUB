{{-- the span of a closed shift: its first → last payment (added at) --}}
@if ($closing->first_payment_at)
    <span class="whitespace-nowrap">{{ $closing->first_payment_at->format('d-m-Y h:i A') }}</span>
    → <span class="whitespace-nowrap">{{ $closing->last_payment_at?->format('d-m-Y h:i A') }}</span>
@else
    {{-- an empty closing (0.00, 2026-10-27) --}}
    <span class="text-slate-500">No cash in / out</span>
@endif
