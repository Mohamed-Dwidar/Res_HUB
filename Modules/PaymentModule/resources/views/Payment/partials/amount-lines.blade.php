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
    // with taxes (2026-11-01, user): "Net (after discount)" + a larger bold "Total (incl. taxes)" = what the customer owes
    $taxed = $kind === 'reservation' && $payable->invoice_requested && $payable->isTaxed();
@endphp
@if ($kind === 'reservation')
    {{-- a reservation (2026-11-08, user, display only): [Amount before discount | Discount] only with a discount; row 1 = Amount
         (booking net after discount) | Extras (net) | Net (before tax); row 2 (invoice requested, taxed) = VAT | Table tax (> 0)
         | Total = booking + extras with taxes; without an invoice: row 1 + Total Due. The extras copy the reservation's tax
         snapshot (ExtraService::applyTax()). --}}
    @php
        $extraRows = $extras != 0 ? $payable->extrasList() : collect();
        $sum = fn ($column) => (float) $extraRows->sum(fn ($extra) => (float) ($extra->{$column} ?? 0));
        $extrasNet = (float) $extraRows->sum(fn ($extra) => (float) ($extra->tax_net ?? $extra->amount));
        $netBeforeTax = $taxed ? (float) $payable->tax_net + $extrasNet : $net + $extrasNet;
        $tableTax = (float) $payable->table_tax + $sum('table_tax');
        $extraDiscount = (float) $payable->tax_extra_discount + $sum('tax_extra_discount');
    @endphp
    @if ($discount > 0)
        <dl class="detail-list amount-lines">
            <div><dt>Amount before discount</dt><dd>{{ number_format($price, 2) }}</dd></div>
            <div><dt>Discount</dt><dd>{{ number_format($discount, 2) }} ({{ $percentage }}%)</dd></div>
        </dl>
    @endif
    <dl class="detail-list amount-lines {{ $discount > 0 ? 'mt-4' : '' }}">
        <div><dt>Amount</dt><dd>{{ number_format($net, 2) }}</dd></div>
        @if ($extras != 0)
            <div><dt>Extras</dt><dd>{{ number_format($extrasNet, 2) }}</dd></div>
        @endif
        <div><dt>Net (before tax)</dt><dd class="font-bold">{{ number_format($netBeforeTax, 2) }}</dd></div>
    </dl>
    @if ($taxed)
        <dl class="detail-list amount-lines mt-4">
            <div><dt>VAT</dt><dd>{{ $payable->tax_vat_exempt ? 'Exempt' : number_format((float) $payable->vat + $sum('vat'), 2) }}</dd></div>
            @if ($tableTax > 0)
                <div><dt>Table Tax</dt><dd>{{ number_format($tableTax, 2) }}</dd></div>
            @endif
            @if ($extraDiscount != 0)
                <div><dt>Extra Discount</dt><dd>{{ number_format($extraDiscount, 2) }}</dd></div>
            @endif
            <div><dt>Total</dt><dd class="amount-total">{{ number_format($payable->totalDue(), 2) }}</dd></div>
        </dl>
        <div class="mt-2 text-xs text-slate-500"><span class="badge badge-status-info">Invoice requested</span> {{ \Modules\PaymentModule\app\Services\Tax\TaxRule::fromSnapshot($payable)?->label() }}</div>
    @else
        <dl class="detail-list amount-lines mt-4">
            <div><dt>Total Due</dt><dd class="amount-total">{{ number_format($payable->totalDue(), 2) }}</dd></div>
        </dl>
        @if ($payable->invoice_requested && $payable->package_id)
            <div class="mt-2 text-xs text-slate-500"><span class="badge badge-status-info">Invoice requested</span> through the package</div>
        @endif
    @endif
@else
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
@endif
@if ($paidLine ?? true)
    <dl class="detail-list amount-lines mt-4">
        <div><dt>Paid</dt><dd class="font-bold text-success">{{ number_format($payable->paid_amount, 2) }}</dd></div>
        <div><dt>{{ $payable->remaining < 0 ? 'Credit' : 'Remaining' }}</dt><dd class="font-bold {{ $payable->remaining > 0 ? 'text-danger' : ($payable->remaining < 0 ? 'text-info' : '') }}">{{ number_format(abs($payable->remaining), 2) }}</dd></div>
    </dl>
@endif
