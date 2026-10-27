{{--
    The money of a reservation / package on two lines (user 2026-10-18): Amount - Discount (only when there is one) - Net Amount
    (bold), [a reservation with "Invoice requested": Invoice + the taxes and the Total, 2026-10-28], then Paid (green) - Remaining
    (red when owed; Credit in blue when negative). Used by the reservation view and the Add Cash In
    details (Payment/partials/payable-details).
    @include('paymentmodule::Payment.partials.amount-lines', ['payable' => $reservation, 'kind' => 'reservation', 'paidLine' => true])
    $paidLine: false = the first line only (a reservation inside a package / not counted is paid elsewhere).
--}}
@php
    $price = (float) $payable->amount;
    $net = $kind === 'reservation' ? (float) $payable->net_amount : (float) $payable->after_discount;
    $discount = round($price - $net, 2);
    $percentage = rtrim(rtrim(number_format((float) $payable->discount_percentage, 2), '0'), '.');
    $extras = method_exists($payable, 'extrasTotal') ? $payable->extrasTotal() : 0;
@endphp
<dl class="detail-list amount-lines">
    <div><dt>Amount</dt><dd>{{ number_format($price, 2) }}</dd></div>
    @if ($discount > 0)
        <div><dt>Discount</dt><dd>{{ number_format($discount, 2) }} ({{ $percentage }}%)</dd></div>
    @endif
    <div><dt>Net Amount</dt><dd class="font-bold">{{ number_format($net, 2) }}
        @if ($extras != 0)
            <span class="block text-xs font-normal text-slate-500">+ extras {{ number_format($extras, 2) }} = due {{ number_format($payable->payableAmount(), 2) }}</span>
        @endif
    </dd></div>
</dl>
{{-- taxes (docs/payments-plan.md §3): a reservation with "Invoice requested" — its tax type, the net before tax, the taxes and what
     it costs (the Paid / Remaining below are against this total) --}}
@if ($kind === 'reservation' && $payable->invoice_requested)
    @php($taxRule = \Modules\PaymentModule\app\Services\Tax\TaxRule::fromSnapshot($payable))
    <dl class="detail-list amount-lines mt-4">
        <div class="wide"><dt>Invoice</dt><dd><span class="badge badge-status-info">Requested</span>
            <span class="text-xs text-slate-500">{{ $payable->isTaxed() ? $taxRule?->label() : ($payable->package_id ? 'through the package' : '') }}</span></dd></div>
        @if ($payable->isTaxed())
            <div><dt>Net Before Tax</dt><dd>{{ number_format((float) $payable->tax_net, 2) }}</dd></div>
            @if ((float) $payable->table_tax != 0)
                <div><dt>Table Tax</dt><dd>{{ number_format((float) $payable->table_tax, 2) }}</dd></div>
            @endif
            <div><dt>VAT</dt><dd>{{ $payable->tax_vat_exempt ? 'Exempt' : number_format((float) $payable->vat, 2) }}</dd></div>
            @if ((float) $payable->tax_extra_discount != 0)
                <div><dt>Extra Discount</dt><dd>{{ number_format((float) $payable->tax_extra_discount, 2) }}</dd></div>
            @endif
            <div><dt>Total</dt><dd class="font-bold">{{ number_format((float) $payable->tax_total, 2) }}</dd></div>
        @endif
    </dl>
@endif
@if ($paidLine ?? true)
    <dl class="detail-list amount-lines mt-4">
        <div><dt>Paid</dt><dd class="font-bold text-success">{{ number_format($payable->paid_amount, 2) }}</dd></div>
        <div><dt>{{ $payable->remaining < 0 ? 'Credit' : 'Remaining' }}</dt><dd class="font-bold {{ $payable->remaining > 0 ? 'text-danger' : ($payable->remaining < 0 ? 'text-info' : '') }}">{{ number_format(abs($payable->remaining), 2) }}</dd></div>
    </dl>
@endif
