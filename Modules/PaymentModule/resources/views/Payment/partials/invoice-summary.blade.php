{{--
    The amounts of a reservation / package view as ONE invoice-style list (2026-11-16, user; display only, figures from
    InvoicePdfService::summary() — the invoice PDF totals use the same order):
    Price → Discount (%) → Extras (sales) → Net before tax → VAT → Table tax → [Extra discount] → Total (incl. taxes) →
    Balance carried / adjustments → Rounding → Due → Paid → Remaining (+ status badge). Lines of 0 are hidden, the totals never;
    without an invoice no tax lines and Total = the net. A reservation paid elsewhere (in a package / not counted): no Due / Paid.
    @include('paymentmodule::Payment.partials.invoice-summary', ['payable' => $reservation])
--}}
@php
    $s = app(\Modules\PaymentModule\app\Services\InvoicePdfService::class)->summary($payable);
    $money = fn ($value) => number_format((float) $value, 2);
@endphp
<dl class="invoice-summary">
    <div><dt>Price</dt><dd>{{ $money($s['price']) }}</dd></div>
    @if ($s['discount'] != 0)
        <div><dt>Discount <span class="text-slate-400">({{ $s['discountPercent'] }}%)</span></dt><dd>{{ $money(-$s['discount']) }}</dd></div>
    @endif
    @if ($s['sales'] != 0)
        <div><dt>Extras <span class="text-slate-400">(sales{{ $s['taxed'] ? ', taxed' : '' }})</span></dt><dd>{{ $money($s['sales']) }}</dd></div>
    @endif
    @if ($s['taxed'])
        <div class="invoice-summary__strong"><dt>Net before tax</dt><dd>{{ $money($s['netBeforeTax']) }}</dd></div>
        @if ($s['exempt'])
            <div><dt>VAT</dt><dd>Exempt</dd></div>
        @elseif ($s['vat'] != 0)
            <div><dt>VAT</dt><dd>{{ $money($s['vat']) }}</dd></div>
        @endif
        @if ($s['table'] != 0)
            <div><dt>Table Tax</dt><dd>{{ $money($s['table']) }}</dd></div>
        @endif
        @if ($s['extraDiscount'] != 0)
            <div><dt>Extra Discount</dt><dd>{{ $money($s['extraDiscount']) }}</dd></div>
        @endif
    @endif
    <div class="invoice-summary__strong invoice-summary__total"><dt>{{ $s['taxed'] ? 'Total incl. taxes' : 'Total' }}</dt><dd>{{ $money($s['total']) }}</dd></div>
    @if ($s['adjustments'] != 0)
        <div><dt>Balance carried / adjustments <span class="text-slate-400">(after tax)</span></dt><dd>{{ $money($s['adjustments']) }}</dd></div>
    @endif
    @if ($s['rounding'] != 0)
        <div><dt>Rounding</dt><dd>{{ $money($s['rounding']) }}</dd></div>
    @endif
    @if ($s['paysHere'])
        <div class="invoice-summary__strong invoice-summary__total"><dt>Due</dt><dd class="text-primary">{{ $money($s['due']) }}</dd></div>
        <div><dt>Paid</dt><dd class="text-success">{{ $money($s['paid']) }}</dd></div>
        <div class="invoice-summary__strong">
            <dt>{{ $s['remaining'] < 0 ? 'Credit' : 'Remaining' }}</dt>
            <dd class="{{ $s['remaining'] > 0 ? 'text-danger' : ($s['remaining'] < 0 ? 'text-info' : '') }}">
                <span class="badge {{ $payable->payment_status_badge_class }} mr-1">{{ $payable->payment_status_label }}</span>
                {{ $money(abs($s['remaining'])) }}
            </dd>
        </div>
    @endif
</dl>
