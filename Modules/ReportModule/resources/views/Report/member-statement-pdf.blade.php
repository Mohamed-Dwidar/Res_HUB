{{--
    Report 2 — the member statement as a PDF (mpdf, A4: ReportActions::memberStatementPdf(), data MemberStatementReport::statement()).
    "Member Statement" / "كشف حساب عميل" — NOT an invoice. Same look as the package statement (public/assets/css/pdf.css, no inline
    CSS); <thead> repeats on every page; footer = printed date + page X of Y; Arabic texts get dir="rtl" ($rtl()).
--}}
@php
    use Modules\ReportModule\app\Services\MemberStatementReport;
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $timeFormat = \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT;
    $money = fn ($value) => number_format((float) $value, 2);
    $rtl = fn ($text) => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $text) ? 'dir="rtl" class="rtl"' : '';
    $balanceClass = fn ($value) => $value > 0.004 ? 'statement-owed' : ($value < -0.004 ? 'statement-credit' : '');
    $logo = $account?->logo && is_file(public_path('uploads/accounts/' . $account->id . '/' . $account->logo))
        ? public_path('uploads/accounts/' . $account->id . '/' . $account->logo)
        : public_path('assets/images/logo.png');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Member Statement - {{ $member->name }}</title>
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
                <div class="receipt-title">Member Statement</div>
                <div class="statement-title-ar" dir="rtl">كشف حساب عميل</div>
                <div class="receipt-muted">Statement date: {{ now()->format($dateFormat) }}</div>
                <div class="receipt-muted">
                    Period: {{ $from ? $from->format($dateFormat) : 'from the start' }} <span class="pdf-arrow">→</span> {{ $to ? $to->format($dateFormat) : 'today and later' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="receipt-table">
        <tr><th>Member</th><td {!! $rtl($member->name) !!}>{{ $member->name }}</td></tr>
        <tr><th>Phone</th><td>{{ $member->phone ?: '-' }}</td></tr>
        @if ($member->company)
            <tr><th>Company</th><td {!! $rtl($member->company->name) !!}>{{ $member->company->name }}</td></tr>
        @endif
    </table>

    <table class="statement-summary">
        <tr>
            <td class="statement-summary-col">
                <table class="statement-summary-table">
                    <tr><th>Opening balance</th><td class="{{ $balanceClass($opening) }}">{{ $money($opening) }}</td></tr>
                    <tr><th>Charges</th><td>{{ $money($charges) }}</td></tr>
                </table>
            </td>
            <td class="statement-summary-col">
                <table class="statement-summary-table">
                    <tr><th>Payments</th><td>{{ $money($payments) }}</td></tr>
                    <tr>
                        <th>{{ $closing < 0 ? 'Closing balance (credit)' : 'Closing balance (owed)' }}</th>
                        <td class="statement-strong {{ $balanceClass($closing) }}">{{ $money(abs($closing)) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="statement-section">Statement</div>
    <table class="statement-table">
        <thead>
            <tr><th>Date</th><th>Type</th><th>Description</th><th class="num">Charge</th><th class="num">Payment</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
            <tr class="statement-total">
                <td></td><td colspan="4">Opening balance</td><td class="num {{ $balanceClass($opening) }}">{{ $money($opening) }}</td>
            </tr>
            @forelse ($lines as $line)
                @php
                    [$type, $text] = MemberStatementReport::describe($line);
                    $muted = $line['kind'] === 'package_reservation' || ($line['kind'] === 'reservation' && $line['charge'] == 0);
                @endphp
                <tr class="{{ $muted ? 'statement-row-muted' : ($loop->even ? 'statement-row-even' : '') }}">
                    <td class="nowrap">{{ $line['day']->format($dateFormat) }}</td>
                    <td class="nowrap">{{ $type }}</td>
                    <td {!! $rtl($text) !!}>{{ $text }}</td>
                    <td class="num">{{ $line['kind'] !== 'payment' ? $money($line['charge']) : '' }}</td>
                    <td class="num {{ $line['payment'] < 0 ? 'statement-owed' : '' }}">{{ $line['kind'] === 'payment' ? $money($line['payment']) : '' }}</td>
                    <td class="num {{ $balanceClass($line['balance']) }}">{{ $money($line['balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="receipt-muted">Nothing in these dates.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="statement-total">
                <td></td>
                <td colspan="2">Closing balance</td>
                <td class="num">{{ $money($charges) }}</td>
                <td class="num">{{ $money($payments) }}</td>
                <td class="num {{ $balanceClass($closing) }}">{{ $money($closing) }}</td>
            </tr>
        </tfoot>
    </table>
    <div class="receipt-muted">A positive balance = owed by the member, negative = the member's credit. This statement is not a tax invoice.</div>
</body>
</html>
