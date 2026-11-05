{{--
    A payslip (mpdf, A5; PayrollActions::payslip(), data PayrollService::payslipData()) in $lang (ar = right to left | en): the account,
    the month, the person (snapshot), earnings / deductions (the line's items), net, the company insurance share, paid. A draft
    gets a DRAFT watermark (the controller). Styles: public/assets/css/pdf.css (receipt-* + payslip-*); no inline CSS.
--}}
@php
    $ar = $lang === 'ar';
    $t = $ar ? [
        'title' => 'قسيمة راتب', 'month' => 'الشهر', 'name' => 'الاسم', 'no' => 'الرقم الوظيفي', 'position' => 'الوظيفة', 'insurance' => 'الرقم التأميني',
        'days' => 'أيام الخدمة', 'earnings' => 'المستحقات', 'deductions' => 'الاستقطاعات', 'net' => 'صافي الراتب', 'company' => 'حصة الشركة في التأمينات',
        'paid' => 'المدفوع', 'remaining' => 'المتبقي', 'sign' => 'توقيع المستلم', 'of' => 'من', 'day' => 'يوم', 'draft' => 'مسودة',
    ] : [
        'title' => 'Payslip', 'month' => 'Month', 'name' => 'Name', 'no' => 'Staff No.', 'position' => 'Job Title', 'insurance' => 'Insurance No.',
        'days' => 'Days in service', 'earnings' => 'Earnings', 'deductions' => 'Deductions', 'net' => 'Net salary', 'company' => 'Company insurance share',
        'paid' => 'Paid', 'remaining' => 'Remaining', 'sign' => 'Received by', 'of' => 'of', 'day' => 'days', 'draft' => 'Draft',
    ];
    $money = fn ($value) => number_format((float) $value, 2);
    $name = $ar && !empty($line->snapshot['name_ar']) ? $line->snapshot['name_ar'] : ($line->snapshot['name'] ?? '-');
    // the fixed labels in Arabic (types / reasons keep their own text)
    $label = function ($item) use ($ar) {
        if (!$ar) {
            return $item->label;
        }
        return match ($item->source) {
            'salary' => 'الراتب الأساسي',
            'tax' => 'ضريبة الدخل',
            'advance' => preg_replace('/^Advance (#\d+) installment$/', 'قسط السلفة $1', $item->label),
            'insurance' => $item->kind === 'info' ? 'حصة الشركة في التأمينات' : 'التأمينات الاجتماعية (حصة الموظف)',
            default => $item->label,
        };
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" @if ($ar) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="{{ public_path('assets/css/pdf.css') }}">
</head>
<body>
    <table class="receipt-head">
        <tr>
            <td>
                @if (is_file(public_path('assets/images/logo.png')))
                    <img src="{{ public_path('assets/images/logo.png') }}" class="receipt-logo">
                @endif
                <div class="receipt-account">{{ $account?->name }}</div>
            </td>
            <td class="receipt-title-cell">
                <div class="receipt-title">{{ $t['title'] }}</div>
                <div class="receipt-no">{{ $run->period->format('m-Y') }}@unless ($run->isLocked()) · {{ $t['draft'] }}@endunless</div>
            </td>
        </tr>
    </table>

    <table class="receipt-table">
        <tr><th>{{ $t['name'] }}</th><td>{{ $name }}</td></tr>
        <tr><th>{{ $t['no'] }}</th><td>{{ $line->snapshot['employee_no'] ?? '-' }}</td></tr>
        <tr><th>{{ $t['position'] }}</th><td>{{ $line->snapshot['position'] ?? '-' }}</td></tr>
        @if (!empty($line->snapshot['insurance_no']))
            <tr><th>{{ $t['insurance'] }}</th><td>{{ $line->snapshot['insurance_no'] }}</td></tr>
        @endif
        <tr><th>{{ $t['days'] }}</th><td>{{ rtrim(rtrim(number_format($line->days_in_service, 2), '0'), '.') }} {{ $t['of'] }} {{ $line->month_days }}</td></tr>
    </table>

    <table class="receipt-table payslip-items">
        <tr><th colspan="2" class="payslip-section">{{ $t['earnings'] }}</th></tr>
        @foreach ($line->items->where('kind', 'earning') as $item)
            <tr><td>{{ $label($item) }}</td><td class="payslip-amount">{{ $money($item->amount) }}</td></tr>
        @endforeach
        <tr><th colspan="2" class="payslip-section">{{ $t['deductions'] }}</th></tr>
        @forelse ($line->items->where('kind', 'deduction') as $item)
            <tr><td>{{ $label($item) }}</td><td class="payslip-amount">{{ $money($item->amount) }}</td></tr>
        @empty
            <tr><td>-</td><td class="payslip-amount">0.00</td></tr>
        @endforelse
    </table>

    <table class="receipt-amount">
        <tr><td>{{ $t['net'] }}</td><td class="receipt-amount-value">{{ $money($line->net) }}</td></tr>
    </table>

    <table class="receipt-table receipt-summary">
        @if ($line->insurance_company)
            <tr><th>{{ $t['company'] }}</th><td>{{ $money($line->insurance_company) }}</td></tr>
        @endif
        @if ($run->isLocked())
            <tr><th>{{ $t['paid'] }}</th><td>{{ $money($line->paid_amount) }}</td></tr>
            <tr><th>{{ $t['remaining'] }}</th><td>{{ $money($line->remaining) }}</td></tr>
        @endif
    </table>

    <table class="receipt-foot">
        <tr><td></td><td class="receipt-sign">{{ $t['sign'] }}</td></tr>
    </table>
</body>
</html>
