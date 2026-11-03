{{-- the plans list Taxes column (VAT on only): "Invoice by default" (pre-ticks "Invoice requested" on a new reservation) or not,
     and the taxes an invoice adds (HasTaxFlags::taxSummary()); any plan can take an invoice once taxes apply --}}
@if ($plan->invoice_by_default)
    <span class="badge badge-status-info">Invoice by default</span>
@else
    <span class="badge badge-status-slate">Invoice on request</span>
@endif
<div class="mt-1 text-xs text-slate-500">{{ $taxAccount ? $plan->taxSummary($taxAccount) : '' }}</div>
