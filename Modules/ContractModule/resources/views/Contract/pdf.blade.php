{{--
    A contract / renewal annex PDF (mpdf, A4; ContractActions::pdf(), data ContractService::pdfData()) in $lang (ar = right to left |
    en). An issued contract prints ONLY its snapshot ($values, $body, $title); a draft prints live values (DRAFT watermark, set by
    the controller). Header (every page): logo + lessor | contract no. + date; page 1: title, the three tables (lessor / tenant /
    unit — the values of the placeholders), the clauses (the template's / owner's text: numbered as written), the signatures (kept
    on one page); footer (every page): "Contract No. · Page X of Y" + the parties' initials. A date inside an Arabic line = <bdo dir="ltr">
    (else its parts are reordered).
    Styles: public/assets/css/pdf.css (contract-*); no inline CSS.
--}}
@php
    $ar = $lang === 'ar';
    $v = $values;
    $t = $ar ? [
        'no' => 'عقد رقم', 'draft' => 'مسودة', 'date' => 'تاريخ التحرير', 'renewal' => 'ملحق تجديد للعقد رقم', 'of_date' => 'بتاريخ',
        'lessor' => 'الطرف الأول (المؤجر)', 'tenant' => 'الطرف الثاني (المستأجر)', 'unit_data' => 'بيانات الوحدة',
        'name' => 'الاسم', 'commercial_register' => 'السجل التجاري', 'tax_number' => 'رقم التسجيل الضريبي', 'representative' => 'الممثل القانوني',
        'title' => 'الصفة', 'national_id' => 'الرقم القومي', 'id' => 'إثبات الشخصية', 'nationality' => 'الجنسية', 'phone' => 'رقم الهاتف المحمول',
        'email' => 'البريد الإلكتروني', 'address' => 'العنوان', 'property' => 'عنوان العين', 'unit' => 'الوحدة', 'unit_number' => 'رقم الوحدة', 'activity' => 'النشاط بالوحدة', 'company' => 'اسم الشركة', 'value' => 'قيمة العقد', 'notes' => 'ملاحظات',
        'start' => 'بداية العقد', 'end' => 'نهاية العقد', 'term' => 'مدة العقد', 'price' => 'القيمة', 'per' => 'عن كل', 'term_amount' => 'قيمة العقد عن المدة',
        'deposit' => 'التأمين', 'net' => 'الصافي قبل الضريبة', 'vat' => 'ضريبة القيمة المضافة', 'table_tax' => 'ضريبة الجدول', 'total' => 'الإجمالي شامل الضرائب',
        'signature' => 'التوقيع', 'sign_date' => 'التاريخ', 'page' => 'صفحة', 'of' => 'من', 'initials' => 'توقيع الطرفين',
    ] : [
        'no' => 'Contract No.', 'draft' => 'Draft', 'date' => 'Date', 'renewal' => 'Renewal annex of contract No.', 'of_date' => 'dated',
        'lessor' => 'First Party (Lessor)', 'tenant' => 'Second Party (Tenant)', 'unit_data' => 'The Unit',
        'name' => 'Name', 'commercial_register' => 'Commercial register', 'tax_number' => 'Tax registration no.', 'representative' => 'Legal representative',
        'title' => 'Title', 'national_id' => 'National ID', 'id' => 'ID', 'nationality' => 'Nationality', 'phone' => 'Phone',
        'email' => 'Email', 'address' => 'Address', 'property' => 'Unit address', 'unit' => 'Unit', 'unit_number' => 'Unit number', 'activity' => 'Activity', 'company' => 'Company', 'value' => 'Contract value', 'notes' => 'Notes',
        'start' => 'Starts', 'end' => 'Ends', 'term' => 'Term', 'price' => 'Price', 'per' => 'per', 'term_amount' => 'Amount for the term',
        'deposit' => 'Security deposit', 'net' => 'Net before tax', 'vat' => 'VAT', 'table_tax' => 'Table tax', 'total' => 'Total with taxes',
        'signature' => 'Signature', 'sign_date' => 'Date', 'page' => 'Page', 'of' => 'of', 'initials' => 'Initials of both parties',
    ];
    $company = ($contract->values['party_type'] ?? $contract->party_type) === 'company';
    $number = $contract->contract_no ? $t['no'] . ' ' . $contract->contract_no : $t['no'] . ' — ' . $t['draft'];
    $account = $account ?? null;
    $logo = $account?->logo && is_file(public_path('uploads/accounts/' . $account->id . '/' . $account->logo))
        ? public_path('uploads/accounts/' . $account->id . '/' . $account->logo)
        : public_path('assets/images/logo.png');
    // a row of a two-column table, only when it has a value
    // a date (digits + "-") inside an Arabic line: kept left to right (LRE … PDF marks; mpdf's page header ignores <bdo> alone)
    $ltr = fn ($text) => $text === '' ? '' : "\u{202A}" . $text . "\u{202C}";
    $rows = fn (array $pairs) => array_filter($pairs, fn ($value) => (string) $value !== '');
    $lessor = $rows([
        $t['name'] => $v['lessor_name'] ?? '', $t['commercial_register'] => $v['lessor_commercial_register'] ?? '', $t['tax_number'] => $v['lessor_tax_number'] ?? '',
        $t['representative'] => $v['lessor_representative'] ?? '', $t['title'] => $v['lessor_representative_title'] ?? '',
        $t['national_id'] => $v['lessor_representative_national_id'] ?? '', $t['phone'] => $v['lessor_phone'] ?? '',
    ]);
    // the contract's own data (2026-11-01: the old DMS layout — what the contract says, not the live member / company)
    $tenant = $rows([
        $t['name'] => $v['tenant_name'] ?? '', $t['representative'] => $v['tenant_representative'] ?? '', $t['title'] => $v['tenant_title'] ?? '',
        $t['company'] => $v['tenant_company'] ?? '', $t['national_id'] => $v['tenant_national_id'] ?? '',
        $t['phone'] => $v['tenant_mobile'] ?? '', $t['email'] => $v['tenant_email'] ?? '',
    ]);
    $unit = $rows([
        $t['property'] => $v['unit_address'] ?? '', $t['unit_number'] => $v['unit_number'] ?? '', $t['activity'] => $v['activity'] ?? '',
        $t['start'] => $v['start_date'] ?? '', $t['end'] => $v['end_date'] ?? '', $t['term'] => $v['term'] ?? '',
        $t['value'] => trim(($v['contract_value'] ?? '') . ' ' . ($v['currency'] ?? '')), $t['deposit'] => trim(($v['deposit'] ?? '') . (($v['deposit'] ?? '') !== '' ? ' ' . ($v['currency'] ?? '') : '')),
        $t['net'] => $v['net_before_tax'] ?? '', $t['vat'] => ($v['vat'] ?? '') !== '' ? $v['vat'] . ' (' . $v['vat_rate'] . ')' : '',
        $t['table_tax'] => ($v['table_tax'] ?? '') !== '' && (float) str_replace(',', '', $v['table_tax']) > 0 ? $v['table_tax'] . ' (' . $v['table_tax_rate'] . ')' : '',
        $t['total'] => $v['total_with_tax'] ?? '', $t['notes'] => $v['notes'] ?? '',
    ]);
    $tenantSigner = ($v['tenant_representative'] ?? '') ?: ($v['tenant_name'] ?? '');
    $lessorSigner = ($v['lessor_representative'] ?? '') ?: ($v['lessor_name'] ?? '');
@endphp
<!DOCTYPE html>
<html @if ($ar) dir="rtl" lang="ar" @else lang="en" @endif>
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
    <link rel="stylesheet" href="{{ public_path('assets/css/pdf.css') }}">
</head>
<body class="contract-pdf {{ $ar ? 'contract-pdf--rtl' : '' }}">
    <htmlpageheader name="contractHeader">
        <table class="contract-head">
            <tr>
                <td class="contract-head__brand">
                    @if (is_file($logo))<img src="{{ $logo }}" class="contract-head__logo">@endif
                    <div class="contract-head__lessor">{{ $v['lessor_name'] ?? $account?->name }}</div>
                </td>
                <td class="contract-head__no">
                    <div class="contract-head__number">{{ $number }}</div>
                    <div>{{ $t['date'] }}: <bdo dir="ltr">{{ $ltr($v['contract_date'] ?? '') }}</bdo></div>
                </td>
            </tr>
        </table>
    </htmlpageheader>
    <htmlpagefooter name="contractFooter">
        <table class="contract-foot">
            <tr>
                <td>{{ $number }} · {{ $t['page'] }} {PAGENO} {{ $t['of'] }} {nbpg}</td>
                <td class="contract-foot__initials">{{ $t['initials'] }}: ............ / ............</td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpageheader name="contractHeader" value="on" show-this-page="1" />
    <sethtmlpagefooter name="contractFooter" value="on" />

    <h1 class="contract-title">{{ $title }}</h1>
    @if ($contract->isRenewal() && $contract->previous)
        <div class="contract-subtitle">{{ $t['renewal'] }} {{ $v['previous_contract_no'] ?? $contract->previous->contract_no }}@if (($v['previous_contract_date'] ?? '') !== '') {{ $t['of_date'] }} <bdo dir="ltr">{{ $ltr($v['previous_contract_date']) }}</bdo>@endif</div>
    @endif

    @foreach ([$t['lessor'] => $lessor, $t['tenant'] => $tenant, $t['unit_data'] => $unit] as $heading => $pairs)
        <table class="contract-table">
            <tr><th colspan="2" class="contract-table__head">{{ $heading }}</th></tr>
            @foreach ($pairs as $label => $value)
                <tr><td class="contract-table__label">{{ $label }}</td><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    @endforeach

    {{-- the clauses: sanitized HTML (ContractService::sanitize() on every save; an issued contract's = its snapshot) --}}
    <div class="contract-body {{ $ar ? 'contract-body--rtl' : '' }}">{!! $body !!}</div>

    <table class="contract-signatures">
        <tr>
            <th>{{ $t['lessor'] }}</th>
            <th>{{ $t['tenant'] }}</th>
        </tr>
        <tr>
            <td>{{ $t['name'] }}: {{ $lessorSigner }}@if (($v['lessor_representative_title'] ?? '') !== '')<br>{{ $t['title'] }}: {{ $v['lessor_representative_title'] }}@endif</td>
            <td>{{ $t['name'] }}: {{ $tenantSigner }}@if (($v['tenant_title'] ?? '') !== '')<br>{{ $t['title'] }}: {{ $v['tenant_title'] }}@endif @if ($company && ($v['tenant_company'] ?? '') !== '')<br>{{ $t['company'] }}: {{ $v['tenant_company'] }}@endif</td>
        </tr>
        <tr>
            <td class="contract-signatures__line">{{ $t['signature'] }}:</td>
            <td class="contract-signatures__line">{{ $t['signature'] }}:</td>
        </tr>
        <tr>
            <td>{{ $t['sign_date'] }}: ......../......../............</td>
            <td>{{ $t['sign_date'] }}: ......../......../............</td>
        </tr>
    </table>
</body>
</html>
