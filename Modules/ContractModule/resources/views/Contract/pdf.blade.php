{{--
    A contract / renewal annex PDF (mpdf, A4; ContractActions::pdf(), data ContractService::pdfData()) in $lang (ar = right to left |
    en). An issued contract prints ONLY its snapshot ($values, $body, $title); a draft prints live values (DRAFT watermark, set by
    the controller). The layout = the account's paper contract (2026-11-25): the logo on every page, the contract itself
    (partials/document, shared with the contract's page), footer "Page X of Y".
    Styles: public/assets/css/pdf.css (contract-*); no inline CSS.
--}}
@php
    $ar = $lang === 'ar';
    $t = $ar ? ['no' => 'عقد رقم', 'draft' => 'مسودة', 'page' => 'صفحة', 'of' => 'من'] : ['no' => 'Contract No.', 'draft' => 'Draft', 'page' => 'Page', 'of' => 'of'];
    $number = $contract->contract_no ? $t['no'] . ' ' . $contract->contract_no : $t['no'] . ' — ' . $t['draft'];
    $account = $account ?? null;
    $logo = $account?->logo && is_file(public_path('uploads/accounts/' . $account->id . '/' . $account->logo))
        ? public_path('uploads/accounts/' . $account->id . '/' . $account->logo)
        : public_path('assets/images/logo.png');
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
        <div class="contract-logo">@if (is_file($logo))<img src="{{ $logo }}" class="contract-logo__image">@endif</div>
    </htmlpageheader>
    <htmlpagefooter name="contractFooter">
        <div class="contract-foot">{{ $t['page'] }} {PAGENO} {{ $t['of'] }} {nbpg}</div>
    </htmlpagefooter>
    <sethtmlpageheader name="contractHeader" value="on" show-this-page="1" />
    <sethtmlpagefooter name="contractFooter" value="on" />

    @include('contractmodule::Contract.partials.document', ['pdf' => true])
</body>
</html>
