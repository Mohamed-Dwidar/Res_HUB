{{-- The list / Schedule Amount cell: what the reservation costs (Reservation::dueAmount(): with its taxes when an invoice is
     requested, docs/payments-plan.md §3) + an "Invoice" badge (+ "taxes" when they were added to the price) --}}
<span class="font-bold text-success">{{ number_format($reservation->dueAmount(), 2) }}</span>
@if ($reservation->invoice_requested)
    <div><span class="badge badge-status-info badge-small" title="{{ $reservation->isTaxed() ? 'Invoice requested: VAT ' . number_format((float) $reservation->vat, 2) . ($reservation->table_tax ? ', table tax ' . number_format((float) $reservation->table_tax, 2) : '') : 'Invoice requested' }}">Invoice</span></div>
@endif
