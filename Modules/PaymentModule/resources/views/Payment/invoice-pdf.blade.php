{{--
    The combined invoice (mpdf A4 + Cairo, 2026-11-08; PaymentActions::invoice(), data InvoicePdfService::data()) in $lang (ar =
    right to left | en), the package statement's style (pdf.css receipt-* / statement-* + invoice-*): header, member, lines
    (the booking / the package + its extras + a package's balance carried over, each BEFORE discount), totals (discount only when there is one; VAT / table tax only
    while taxed), payments (refunds minus, withholding certificates named), paid, remaining. With "Invoice requested" the
    footer says the official electronic invoice is issued through the ETA portal. No inline CSS.
--}}
@php
    $ar = $lang === 'ar';
    $t = $ar ? [
        'title' => 'فاتورة', 'date' => 'التاريخ', 'member' => 'العميل', 'phone' => 'الهاتف', 'company' => 'الشركة',
        'item' => 'البند', 'qty' => 'الكمية × السعر', 'amount' => 'المبلغ', 'lines' => 'البنود', 'subtotal' => 'الإجمالي الفرعي', 'totalTax' => 'الإجمالي (شامل الضرائب)', 'booking' => 'حجز',
        'package' => 'باقة', 'period' => 'الفترة', 'sessions' => 'حجوزات', 'hours' => 'ساعة', 'carried' => 'رصيد مرحل',
        'before' => 'المبلغ قبل الخصم', 'discount' => 'الخصم', 'net' => 'الصافي قبل الضريبة', 'vat' => 'ضريبة القيمة المضافة',
        'exempt' => 'معفى', 'table' => 'ضريبة الجدول', 'extraDiscount' => 'خصم إضافي', 'total' => 'الإجمالي',
        'payments' => 'المدفوعات', 'receipt' => 'رقم الإيصال', 'method' => 'طريقة الدفع', 'refund' => 'استرداد',
        'withholding' => 'شهادة خصم ضريبة', 'none' => 'لا توجد مدفوعات بعد.', 'paid' => 'المدفوع', 'remaining' => 'المتبقي',
        'credit' => 'رصيد دائن', 'eta' => 'تصدر الفاتورة الإلكترونية الرسمية من خلال منظومة الفاتورة الإلكترونية (ETA).',
        'printed' => 'طُبعت', 'page' => 'صفحة', 'of' => 'من', 'no' => 'رقم',
    ] : [
        'title' => 'Invoice', 'date' => 'Date', 'member' => 'Customer', 'phone' => 'Phone', 'company' => 'Company',
        'item' => 'Item', 'qty' => 'Qty × Price', 'amount' => 'Amount', 'lines' => 'Items', 'subtotal' => 'Subtotal', 'totalTax' => 'Total (incl. taxes)', 'booking' => 'Reservation',
        'package' => 'Package', 'period' => 'Period', 'sessions' => 'reservations', 'hours' => 'h', 'carried' => 'Balance carried over',
        'before' => 'Amount before discount', 'discount' => 'Discount', 'net' => 'Net before tax', 'vat' => 'VAT',
        'exempt' => 'Exempt', 'table' => 'Table tax', 'extraDiscount' => 'Extra discount', 'total' => 'Total',
        'payments' => 'Payments', 'receipt' => 'Receipt No.', 'method' => 'Method', 'refund' => 'Refund',
        'withholding' => 'Withholding tax certificate', 'none' => 'No payments yet.', 'paid' => 'Paid', 'remaining' => 'Remaining',
        'credit' => 'Credit', 'eta' => 'The official electronic invoice is issued through the ETA portal.',
        'printed' => 'Printed', 'page' => 'Page', 'of' => 'of', 'no' => 'No.',
    ];
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $timeFormat = \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT;
    $money = fn ($value) => number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    // English page: a text that STARTS with Arabic is written right to left (like the package statement)
    $rtl = fn ($text) => !$ar && preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $text) ? 'dir="rtl" class="rtl"' : '';
    $logo = $account?->logo && is_file(public_path('uploads/accounts/' . $account->id . '/' . $account->logo))
        ? public_path('uploads/accounts/' . $account->id . '/' . $account->logo)
        : public_path('assets/images/logo.png');
    $number = ($kind === 'package' ? 'P-' : 'R-') . $payable->id;
    // Cairo has no → (the period text): drawn with DejaVu Sans; a date / number next to Arabic stays left to right (<bdo>)
    $arrows = fn ($text) => str_replace('→', '<span class="pdf-arrow">→</span>', e($text));
    $ltr = fn ($text) => '<bdo dir="ltr">' . e($text) . '</bdo>';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" @if ($ar) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <title>{{ $t['title'] }} {{ $number }}</title>
    <link rel="stylesheet" href="{{ public_path('assets/css/pdf.css') }}">
</head>
<body class="{{ $ar ? 'invoice-ar' : '' }}">
    <htmlpagefooter name="invoiceFooter">
        @if ($invoiceRequested)
            <div class="invoice-eta-note">{{ $t['eta'] }}</div>
        @endif
        <table class="statement-footer">
            <tr>
                <td>{{ $t['printed'] }} {!! $ltr(now()->format($dateFormat . ' ' . $timeFormat)) !!}</td>
                <td class="statement-footer-right">{{ $t['page'] }} {PAGENO} {{ $t['of'] }} {nbpg}</td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="invoiceFooter" value="on" />

    {{-- 1. header --}}
    <table class="receipt-head">
        <tr>
            <td>
                @if (is_file($logo))
                    <img src="{{ $logo }}" class="receipt-logo">
                @endif
                <div class="receipt-account" {!! $rtl($account?->name) !!}>{{ $account?->name }}</div>
                @if ($account?->address)<div class="receipt-muted" {!! $rtl($account->address) !!}>{{ $account->address }}</div>@endif
                @if ($account?->phone)<div class="receipt-muted">{{ $account->phone }}</div>@endif
            </td>
            <td class="receipt-title-cell">
                <div class="receipt-title">{{ $t['title'] }}</div>
                <div class="receipt-no">{{ $t['no'] }} {!! $ltr($number) !!}</div>
                <div class="receipt-muted">{{ $t['date'] }}: {!! $ltr(now()->format($dateFormat)) !!}</div>
            </td>
        </tr>
    </table>

    {{-- 2. the customer --}}
    <table class="receipt-table">
        <tr><th>{{ $t['member'] }}</th><td {!! $rtl($member?->name) !!}>{{ $member?->name ?? '-' }}</td></tr>
        <tr><th>{{ $t['phone'] }}</th><td>{{ $member?->phone ?: '-' }}</td></tr>
        @if ($member?->company)
            <tr><th>{{ $t['company'] }}</th><td {!! $rtl($member->company->name) !!}>{{ $member->company->name }}</td></tr>
        @endif
    </table>

    {{-- 3. items: the booking / the package (before discount), its extras, a package's balance carried over --}}
    <div class="statement-section">{{ $t['lines'] }}</div>
    <table class="statement-table">
        <thead>
            <tr><th>{{ $t['date'] }}</th><th>{{ $t['item'] }}</th><th class="num">{{ $t['qty'] }}</th><th class="num">{{ $t['amount'] }}</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="nowrap">{{ $main['date']?->format($dateFormat) ?? '-' }}</td>
                <td>
                    @if ($main['kind'] === 'booking')
                        <div>{{ $t['booking'] }} #{{ $payable->id }} · <span {!! $rtl($main['title']) !!}>{{ $main['title'] }}</span></div>
                        @if ($main['sub'])<div class="receipt-muted" {!! $rtl($main['sub']) !!}>{!! $arrows($main['sub']) !!}</div>@endif
                    @else
                        <div>{{ $t['package'] }} · <span {!! $rtl($main['title']) !!}>{{ $main['title'] }}</span></div>
                        <div class="receipt-muted">{{ $t['period'] }}: {!! $ltr(($main['period'][0]?->format($dateFormat) ?? '-')) !!} <span class="pdf-arrow">{{ $ar ? '←' : '→' }}</span> {!! $ltr(($main['period'][1]?->format($dateFormat) ?? '-')) !!}
                            · {{ $main['sessions'] }} {{ $t['sessions'] }}@if ($main['hours'] > 0) ({{ $qty($main['hours']) }} {{ $t['hours'] }})@endif</div>
                    @endif
                </td>
                <td class="num">1</td>
                <td class="num">{{ $money($main['amount']) }}</td>
            </tr>
            @foreach ($extras as $extra)
                <tr>
                    <td class="nowrap">{{ $extra->date?->format($dateFormat) }}</td>
                    <td>
                        <span {!! $rtl($extra->item) !!}>{{ $extra->item }}</span>
                        @if ($extra->incomeCategory && $extra->incomeCategory->name !== $extra->item)<span class="receipt-muted"> · {{ $extra->incomeCategory->name }}</span>@endif
                    </td>
                    <td class="num">{{ $qty($extra->quantity) }} × {{ $money($extra->unit_price) }}</td>
                    <td class="num">{{ $money($extra->amount) }}</td>
                </tr>
            @endforeach
            @if ($adjustments != 0)
                <tr><td></td><td>{{ $t['carried'] }}</td><td></td><td class="num">{{ $money($adjustments) }}</td></tr>
            @endif
        </tbody>
    </table>

    {{-- 4. totals: Subtotal, Discount (only when there is one), Net before tax, VAT / Table tax (each only > 0), Total --}}
    <table class="invoice-totals">
        <tr>
            <td class="invoice-totals-space"></td>
            <td class="invoice-totals-col">
                <table class="statement-summary-table">
                    <tr><th>{{ $t['subtotal'] }}</th><td>{{ $money($subtotal) }}</td></tr>
                    @if ($discount > 0)
                        <tr><th>{{ $t['discount'] }}</th><td>{{ $money($discount) }} ({{ $discountPercent }}%)</td></tr>
                    @endif
                    @if ($tax)
                        {{-- only with taxes (user 2026-11-08): without them it equals the Total --}}
                        <tr><th>{{ $t['net'] }}</th><td>{{ $money($netBeforeTax) }}</td></tr>
                        @if ($tax['vat'] > 0)
                            <tr><th>{{ $t['vat'] }}</th><td>{{ $money($tax['vat']) }}</td></tr>
                        @endif
                        @if ($tax['table'] > 0)
                            <tr><th>{{ $t['table'] }}</th><td>{{ $money($tax['table']) }}</td></tr>
                        @endif
                        @if ($tax['discount'] != 0)
                            <tr><th>{{ $t['extraDiscount'] }}</th><td>{{ $money($tax['discount']) }}</td></tr>
                        @endif
                    @endif
                    <tr class="invoice-total-row"><th>{{ $tax ? $t['totalTax'] : $t['total'] }}</th><td>{{ $money($total) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- 5. payments (refunds minus, withholding certificates named; who collected is not shown) --}}
    <div class="statement-section">{{ $t['payments'] }}</div>
    <table class="statement-table">
        <thead>
            <tr><th>{{ $t['receipt'] }}</th><th>{{ $t['date'] }}</th><th>{{ $t['method'] }}</th><th class="num">{{ $t['amount'] }}</th></tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td>{{ $payment->payment_no }}</td>
                    <td class="nowrap">{{ $payment->paid_at?->format($dateFormat) }}</td>
                    <td>
                        @if ($payment->type === 'withholding')
                            {{ $t['withholding'] }}
                        @elseif ($payment->type === 'refund')
                            {{ $t['refund'] }}{{ $payment->paymentMethod ? ' · ' . $payment->paymentMethod->name : '' }}
                        @else
                            {{ $payment->paymentMethod?->name ?? '-' }}
                        @endif
                    </td>
                    <td class="num {{ $payment->type === 'refund' ? 'statement-owed' : '' }}">{{ $money($payment->signed_amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="receipt-muted">{{ $t['none'] }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="invoice-totals">
        <tr>
            <td class="invoice-totals-space"></td>
            <td class="invoice-totals-col">
                <table class="statement-summary-table">
                    <tr><th>{{ $t['paid'] }}</th><td class="statement-strong">{{ $money($paid) }}</td></tr>
                    <tr>
                        <th>{{ $remaining < 0 ? $t['credit'] : $t['remaining'] }}</th>
                        <td class="statement-strong {{ $remaining > 0 ? 'statement-owed' : ($remaining < 0 ? 'statement-credit' : '') }}">{{ $money(abs($remaining)) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
