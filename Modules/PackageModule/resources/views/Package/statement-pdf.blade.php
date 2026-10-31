{{--
    The customer package statement (PDF, mpdf, A4 portrait: PackageActions::statement(), data PackageService::statement()).
    "Package Statement" / "كشف حساب باقة" — NOT an invoice (official tax invoices come from the ETA system).
    Styles in public/assets/css/pdf.css (mpdf can't read custom.css; no inline CSS). <thead> rows repeat on every page (mpdf);
    the footer (page X of Y, printed date) is an mpdf page footer. Arabic texts get dir="rtl" ($rtl()); no collector shown.
--}}
@php
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $timeFormat = \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT;
    $money = fn ($value) => number_format((float) $value, 2);
    $hoursText = fn ($minutes) => rtrim(rtrim(number_format($minutes / 60, 2), '0'), '.');
    // a text that STARTS with Arabic is written right to left (a mixed "Sherif (شركة ...)" stays left to right; mpdf's bidi
    // still shapes and orders its Arabic part)
    $rtl = fn ($text) => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $text) ? 'dir="rtl" class="rtl"' : '';
    $member = $package->member;
    $logo = $account?->logo && is_file(public_path('uploads/accounts/' . $account->id . '/' . $account->logo))
        ? public_path('uploads/accounts/' . $account->id . '/' . $account->logo)
        : public_path('assets/images/logo.png');
    $paid = (float) $package->paid_amount;
    $remaining = (float) $package->remaining;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Package Statement - {{ $package->name }}</title>
    <link rel="stylesheet" href="{{ public_path('assets/css/pdf.css') }}">
</head>
<body>
    <htmlpagefooter name="statementFooter">
        <table class="statement-footer">
            <tr>
                <td>Printed {{ now()->format($dateFormat . ' ' . $timeFormat) }}</td>
                <td class="statement-footer-right">Page {PAGENO} of {nbpg}</td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="statementFooter" value="on" />

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
                <div class="receipt-title">Package Statement</div>
                <div class="statement-title-ar" dir="rtl">كشف حساب باقة</div>
                <div class="receipt-no" {!! $rtl($package->name) !!}>{{ $package->name }}</div>
                <div class="receipt-muted">Statement date: {{ now()->format($dateFormat) }}</div>
            </td>
        </tr>
    </table>

    {{-- 2. member --}}
    <table class="receipt-table">
        <tr><th>Member</th><td {!! $rtl($member?->name) !!}>{{ $member?->name ?? '-' }}</td></tr>
        <tr><th>Phone</th><td>{{ $member?->phone ?: '-' }}</td></tr>
        @if ($member?->company)
            <tr><th>Company</th><td {!! $rtl($member->company->name) !!}>{{ $member->company->name }}</td></tr>
        @endif
    </table>

    {{-- 3. summary --}}
    <table class="statement-summary">
        <tr>
            <td class="statement-summary-col">
                <table class="statement-summary-table">
                    <tr><th>Period</th><td class="nowrap">{{ $package->date_from?->format($dateFormat) ?? '-' }} <span class="pdf-arrow">→</span> {{ $package->date_to?->format($dateFormat) ?? ($package->date_from ? 'continues' : '-') }}</td></tr>
                    <tr><th>Reservations</th><td>{{ $reservations->count() }}@if ($hours > 0) ({{ $hoursText($hours * 60) }} h)@endif @if ($cancelledCount)<span class="receipt-muted">+ {{ $cancelledCount }} cancelled</span>@endif</td></tr>
                    <tr><th>Reservations total</th><td>{{ $money($reservationsTotal) }}</td></tr>
                    <tr><th>Discount</th><td>{{ rtrim(rtrim(number_format((float) $package->discount_percentage, 2), '0'), '.') }}%</td></tr>
                    <tr><th>Net</th><td>{{ $money($package->after_discount) }}</td></tr>
                </table>
            </td>
            <td class="statement-summary-col">
                <table class="statement-summary-table">
                    <tr><th>Extras total</th><td>{{ $money($extrasTotal) }}</td></tr>
                    @if ($adjustments->isNotEmpty())
                        <tr><th>Balance carried over</th><td>{{ $money($adjustmentsTotal) }}</td></tr>
                    @endif
                    <tr><th>Total due</th><td class="statement-strong">{{ $money($due) }}</td></tr>
                    <tr><th>Total paid</th><td class="statement-strong">{{ $money($paid) }}</td></tr>
                    <tr>
                        <th>{{ $remaining < 0 ? 'Credit' : 'Remaining' }}</th>
                        <td class="statement-strong {{ $remaining > 0 ? 'statement-owed' : ($remaining < 0 ? 'statement-credit' : '') }}">{{ $money(abs($remaining)) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{--
        4. reservations by date: every row, the even ones shaded; a cancelled like one (not counted) on a red background,
        its hours and amount 0 (not in the totals: the totals row = the counted ones)
    --}}
    <div class="statement-section">Reservations</div>
    <table class="statement-table">
        <thead>
            <tr><th>Date</th><th>Time</th><th>Space › Unit</th><th class="num">Hours</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @forelse ($allReservations as $reservation)
                @php
                    $start = $reservation->start_at;
                    $end = $reservation->end_at;
                    $type = $reservation->subscriptionType;
                    $counted = $reservation->isCounted();
                @endphp
                <tr class="{{ !$counted ? 'statement-row-cancelled' : ($loop->even ? 'statement-row-even' : '') }}">
                    <td class="nowrap">{{ $start?->format($dateFormat . ' D') }}</td>
                    <td class="nowrap">
                        @if ($type?->isFullDay())
                            Full day
                        @elseif ($type?->auto_renew || $type?->is_contract)
                            until {{ $end?->format($dateFormat) ?? '-' }}
                        @elseif ($end && $start->isSameDay($end))
                            {{ $start->format($timeFormat) }} <span class="pdf-arrow">→</span> {{ $end->format($timeFormat) }}
                        @else
                            {{ $start?->format($timeFormat) }} <span class="pdf-arrow">→</span> {{ $end?->format($dateFormat . ' ' . $timeFormat) ?? '-' }}
                        @endif
                    </td>
                    <td {!! $rtl(($reservation->space?->name ?? '') . ($reservation->unit?->name ?? '')) !!}>
                        {{ $reservation->space?->name ?? '-' }} › {{ $reservation->unit?->name ?? '-' }}
                        @unless ($counted)
                            <span class="statement-cancelled-label">· {{ $reservation->status?->name ?? 'Cancelled' }}</span>
                        @endunless
                    </td>
                    <td class="num">{{ !$counted ? '0' : ($minutes[$reservation->id] ? $hoursText($minutes[$reservation->id]) : '-') }}</td>
                    <td class="num">{{ $money($counted ? $reservation->net_amount : 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="receipt-muted">No reservations.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="statement-total">
                <td colspan="3">Total ({{ $reservations->count() }})</td>
                <td class="num">{{ $hours > 0 ? $hoursText($hours * 60) : '-' }}</td>
                <td class="num">{{ $money($reservationsTotal) }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- 5. extras (type extra; the adjustments = one "balance carried over" line); hidden when none --}}
    @if ($extras->isNotEmpty() || $adjustments->isNotEmpty())
        <div class="statement-section">Extras</div>
        <table class="statement-table">
            <thead>
                <tr><th>Date</th><th>Item</th><th class="num">Qty × Price</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($extras as $extra)
                    <tr>
                        <td class="nowrap">{{ $extra->date?->format($dateFormat) }}</td>
                        <td {!! $rtl($extra->item) !!}>{{ $extra->item }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format($extra->quantity, 2), '0'), '.') }} × {{ $money($extra->unit_price) }}</td>
                        <td class="num">{{ $money($extra->amount) }}</td>
                    </tr>
                @endforeach
                @if ($adjustments->isNotEmpty())
                    <tr>
                        <td></td>
                        <td>Balance carried over</td>
                        <td></td>
                        <td class="num">{{ $money($adjustmentsTotal) }}</td>
                    </tr>
                @endif
            </tbody>
            <tfoot>
                <tr class="statement-total">
                    <td colspan="3">Total</td>
                    <td class="num">{{ $money($extrasTotal + $adjustmentsTotal) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- 6. payments (refunds minus, withholding certificates apart; who collected is not shown) --}}
    <div class="statement-section">Cash In</div>
    <table class="statement-table">
        <thead>
            <tr><th>Receipt No.</th><th>Date</th><th>Method</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td>{{ $payment->payment_no }}</td>
                    <td class="nowrap">{{ $payment->paid_at?->format($dateFormat) }}</td>
                    <td>
                        @if ($payment->type === 'withholding')
                            Withholding tax certificate
                        @elseif ($payment->type === 'refund')
                            Refund{{ $payment->paymentMethod ? ' · ' . $payment->paymentMethod->name : '' }}
                        @else
                            {{ $payment->paymentMethod?->name ?? '-' }}
                        @endif
                    </td>
                    <td class="num {{ $payment->type === 'refund' ? 'statement-owed' : '' }}">{{ $money($payment->signed_amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="receipt-muted">No cash in yet.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="statement-total">
                <td colspan="3">Total paid</td>
                <td class="num">{{ $money($paid) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
