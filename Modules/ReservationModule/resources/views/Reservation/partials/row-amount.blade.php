{{-- The list / Schedule Amount cell (2026-11-11): what the member owes for it = Reservation::totalDue() (the booking —
     dueAmount(): with its taxes when an invoice is requested, docs/payments-plan.md §3 — + its extras), so the Paid / Remaining
     columns add up; with extras a small line "<booking> · extras ±X"; + an "Invoice" badge --}}
@php($extras = $reservation->extrasTotal())
<span class="font-bold text-success">{{ number_format($reservation->totalDue(), 2) }}</span>
@if ($extras != 0)
    <div class="text-xs text-slate-500">{{ number_format($reservation->dueAmount(), 2) }} · extras {{ $extras < 0 ? '−' : '+' }}{{ number_format(abs($extras), 2) }}</div>
@endif
@if ($reservation->invoice_requested)
    <div><span class="badge badge-status-info badge-small" title="{{ $reservation->isTaxed() ? 'Invoice requested: VAT ' . number_format((float) $reservation->vat, 2) . ($reservation->table_tax ? ', table tax ' . number_format((float) $reservation->table_tax, 2) : '') : 'Invoice requested' }}">Invoice</span></div>
@endif
