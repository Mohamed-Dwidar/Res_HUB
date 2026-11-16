{{--
    The quick Due | Paid | Remaining box of a reservation / package view header (2026-11-16, user; figures = InvoicePdfService::summary(),
    the same as the Amounts card). A reservation paid elsewhere (in a package / not counted): no box.
    @include('paymentmodule::Payment.partials.money-box', ['payable' => $package])
--}}
@php($s = app(\Modules\PaymentModule\app\Services\InvoicePdfService::class)->summary($payable))
@if ($s['paysHere'])
    <div class="money-box ml-auto">
        <div class="money-box__item money-box__item--due" title="{{ $s['taxed'] ? 'Total incl. taxes' : 'Total' }}{{ $s['adjustments'] || $s['rounding'] ? ' + adjustments' : '' }}">
            <span>Due</span><b>{{ number_format($s['due'], 2) }}</b>
        </div>
        <div class="money-box__item money-box__item--paid">
            <span>Paid</span><b>{{ number_format($s['paid'], 2) }}</b>
        </div>
        <div class="money-box__item {{ $s['remaining'] > 0 ? 'money-box__item--owed' : ($s['remaining'] < 0 ? 'money-box__item--credit' : 'money-box__item--paid') }}">
            <span>{{ $s['remaining'] < 0 ? 'Credit' : 'Remaining' }}</span><b>{{ number_format(abs($s['remaining']), 2) }}</b>
        </div>
    </div>
@endif
