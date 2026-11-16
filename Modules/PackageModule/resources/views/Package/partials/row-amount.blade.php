{{-- the list Amount column (2026-11-11): what the package is due = Package::payableAmount() (net after discount + extras /
     adjustments; the Paid / Remaining columns are against it), green bold; under it "<price> · −<discount>% · extras ±X"
     when there is a discount or extras --}}
@php($extras = $package->extrasTotal())
<span class="font-bold text-success">{{ number_format($package->payableAmount(), 2) }}</span>
@if ((float) $package->discount_percentage > 0 || $extras != 0)
    <div class="text-xs text-slate-500">{{ number_format($package->amount, 2) }}@if ((float) $package->discount_percentage > 0) · −{{ rtrim(rtrim(number_format($package->discount_percentage, 2), '0'), '.') }}%@endif @if ($extras != 0)· extras {{ $extras < 0 ? '−' : '+' }}{{ number_format(abs($extras), 2) }}@endif</div>
@endif
@if ($package->invoice_requested)
    <div><span class="badge badge-status-info badge-small" title="{{ $package->isTaxed() ? 'Invoice requested: VAT ' . number_format((float) $package->vat, 2) . ($package->table_tax ? ', table tax ' . number_format((float) $package->table_tax, 2) : '') : 'Invoice requested' }}">Invoice</span></div>
@endif
