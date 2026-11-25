{{--
    A whole contract / renewal annex as printed ($contract, $lang, $values, $body, $title, $annex = ContractService::pdfData()): title,
    date, the lessor / lessee / unit tables, the signatures, the clauses (an annex: title, text, signatures). ONE markup for the PDF
    (Contract/pdf, $pdf = true: the clauses from page 2; styles pdf.css) and the contract's page (Contract/show; styles custom.css
    .contract-document).
--}}
@php
    $ar = $lang === 'ar';
    $v = $values;
    $t = $ar ? [
        'date' => 'تاريخ التعاقد', 'renewal' => 'ملحق تجديد للعقد رقم', 'of_date' => 'بتاريخ',
        'lessor' => 'المؤجر', 'tenant' => 'المستأجر', 'unit_data' => 'بيانات الوحدة المؤجرة',
        'commercial_register' => 'سجل تجاري', 'tax_number' => 'الرقم التسجيل الضريبي', 'tax_card' => 'رقم البطاقة الضريبية', 'representative' => 'الممثل القانوني',
        'title' => 'صفته', 'national_id' => 'بطاقة الرقم القومي', 'phone' => 'رقم الهاتف المحمول', 'email' => 'البريد الالكتروني',
        'property' => 'عنوان العين', 'unit_number' => 'رقم الوحدة', 'activity' => 'النشاط بالوحدة', 'value' => 'قيمة العقد', 'notes' => 'ملاحظات',
        'start' => 'بداية العقد', 'end' => 'نهاية العقد', 'term' => 'مدة العقد', 'deposit' => 'التأمين', 'for' => 'وعنها',
        'signature' => 'التوقيع',
    ] : [
        'date' => 'Contract date', 'renewal' => 'Renewal annex of contract No.', 'of_date' => 'dated',
        'lessor' => 'Lessor', 'tenant' => 'Lessee', 'unit_data' => 'The leased unit',
        'commercial_register' => 'Commercial register', 'tax_number' => 'Tax registration no.', 'tax_card' => 'Tax card no.', 'representative' => 'Legal representative',
        'title' => 'Title', 'national_id' => 'National ID', 'phone' => 'Mobile', 'email' => 'Email',
        'property' => 'Unit address', 'unit_number' => 'Unit number', 'activity' => 'Activity', 'value' => 'Contract value', 'notes' => 'Notes',
        'start' => 'Starts', 'end' => 'Ends', 'term' => 'Term', 'deposit' => 'Security deposit', 'for' => 'represented by',
        'signature' => 'Signature',
    ];
    $company = ($contract->values['party_type'] ?? $contract->party_type) === 'company';
    // dates, numbers, phones, emails: kept left to right (LRE … PDF marks)
    $ltr = fn ($text) => (string) $text === '' ? '' : "\u{202A}" . $text . "\u{202C}";
    $get = fn ($name) => trim((string) ($v[$name] ?? ''));
    // the lessee: one name since 2026-11-25 (an older company contract: its company name)
    $tenantName = $company && $get('tenant_company') !== '' ? $get('tenant_company') : $get('tenant_name');
    $representative = $get('tenant_representative');
    $value = $get('contract_value') === '' ? '' : $ltr($get('contract_value')) . ' ' . $get('currency');
    $deposit = $get('deposit') === '' ? '' : $ltr($get('deposit')) . ' ' . $get('currency');

    // rows of [label, value] pairs: one pair = the value over 3 columns, two = label | value | label | value; an empty value is left out
    $tables = [
        ['head' => [$t['lessor'], $get('lessor_name')], 'rows' => [
            [[$t['commercial_register'], $get('lessor_commercial_register')], [$t['tax_number'], $ltr($get('lessor_tax_number'))]],
            [[$t['representative'], $get('lessor_representative')]],
            [[$t['title'], $get('lessor_representative_title')], [$t['national_id'], $ltr($get('lessor_representative_national_id'))]],
        ]],
        ['head' => [$t['tenant'], $tenantName], 'rows' => [
            // a company only
            $company ? [[$t['commercial_register'], $get('tenant_commercial_register')], [$t['tax_card'], $ltr($get('tenant_tax_number'))]] : [],
            [[$t['representative'], $representative]],
            [[$t['title'], $get('tenant_title')], [$t['national_id'], $ltr($get('tenant_national_id'))]],
            [[$t['phone'], $ltr($get('tenant_mobile'))], [$t['email'], $ltr($get('tenant_email'))]],
        ]],
        ['head' => [$t['unit_data']], 'rows' => [
            [[$t['property'], $get('unit_address')]],
            [[$t['unit_number'], $ltr($get('unit_number'))]],
            [[$t['activity'], $get('activity')]],
            [[$t['start'], $ltr($get('start_date'))], [$t['end'], $ltr($get('end_date'))]],
            [[$t['term'], $get('term')], [$get('contract_value_label') ?: $t['value'], $value]],
            [[$t['deposit'], $deposit]],
            [[$t['notes'], $get('notes')]],
        ]],
    ];
    $lessorSigner = $get('lessor_name') . ($get('lessor_representative') !== '' && $get('lessor_representative') !== $get('lessor_name') ? ' ' . $t['for'] . ' ' . $get('lessor_representative') : '');
@endphp
<h1 class="contract-title">{{ $title }}</h1>
@if (!empty($annex))
    {{-- the renewal annex (2026-11-25): title, its text, the two signatures; no tables --}}
    <div class="contract-body contract-body--print contract-body--annex {{ $ar ? 'contract-body--rtl' : '' }}">{!! $body !!}</div>
    <table class="contract-annex-signatures"><tr>
        <td>{{ $ar ? 'توقيع المؤجر' : 'Lessor signature' }}</td>
        <td>{{ $ar ? 'توقيع المستأجر' : 'Lessee signature' }}</td>
    </tr></table>
@else
@if ($contract->isRenewal() && $contract->previous)
    <div class="contract-subtitle">{{ $t['renewal'] }} {{ $v['previous_contract_no'] ?? $contract->previous->contract_no }}@if ($get('previous_contract_date') !== '') {{ $t['of_date'] }} {{ $ltr($get('previous_contract_date')) }}@endif</div>
@endif
<div class="contract-date"><b>{{ $t['date'] }} :</b> &nbsp; {{ $ltr($get('contract_date')) }}</div>

@foreach ($tables as $table)
    <table class="contract-table{{ $ar ? ' contract-table--rtl' : '' }}">
        <tr>
            <td class="contract-table__head contract-table__label" @if (count($table['head']) === 1) colspan="4" @endif>{{ $table['head'][0] }}</td>
            @if (count($table['head']) > 1)<td class="contract-table__head" colspan="3">{{ $table['head'][1] }}</td>@endif
        </tr>
        @foreach ($table['rows'] as $pairs)
            @php($pairs = array_values(array_filter($pairs, fn ($pair) => $pair[1] !== '')))
            @if ($pairs)
                <tr>
                    @foreach ($pairs as $index => [$label, $text])
                        <td class="contract-table__label{{ $index ? ' contract-table__label--second' : '' }}">{{ $label }}</td>
                        <td @if (count($pairs) === 1) colspan="3" @else class="contract-table__half" @endif>{{ $text }}</td>
                    @endforeach
                </tr>
            @endif
        @endforeach
    </table>
@endforeach

<table class="contract-signatures{{ $ar ? ' contract-table--rtl' : '' }}">
    <tr>
        <td class="contract-signatures__label">{{ $t['lessor'] }}</td>
        <td class="contract-signatures__name">{{ $lessorSigner }}</td>
        <td class="contract-signatures__label">{{ $t['tenant'] }}</td>
        <td class="contract-signatures__name">{{ $tenantName }}</td>
    </tr>
    <tr>
        <td class="contract-signatures__label contract-signatures__line">{{ $t['signature'] }}</td>
        <td class="contract-signatures__line"></td>
        <td class="contract-signatures__label contract-signatures__line">{{ $t['signature'] }}</td>
        <td class="contract-signatures__line"></td>
    </tr>
</table>

{{-- the clauses: sanitized HTML (ContractService::sanitize() on every save; an issued contract's = its snapshot) --}}
@if (!empty($pdf))<pagebreak />@endif
<div class="contract-body contract-body--print {{ $ar ? 'contract-body--rtl' : '' }}">{!! $body !!}</div>
@endif
