{{--
    A contract draft page (Create / Renew contract: $draft from ContractService::draftFor(), $contract null; Edit draft: $contract).
    $reservation = the period it is made from. 2026-11-01 (user): ALL the printed data is the contract's own (like the old DMS form),
    pre-filled from the reservation / member / company / Settings › Contracts (a renewal: from the previous contract) and edited
    here freely: contract data | معلومات المستأجر | معلومات الوحدة (Arabic labels, right to left). Never written to the member /
    company unless "Also save to member / company" (their empty fields only). The end, the duration and the value follow the start
    + term while untouched (custom.js contractTermEnd()). To issue: tenant name + national ID (individual) / company name + legal
    representative (company).
--}}
@php
    $member = $reservation->member;
    $company = $member && $member->company_id ? $member->company : null;
    $value = fn ($field) => $contract ? $contract->{$field} : ($draft[$field] ?? null);
    // the issue requirement note ($required would be read by the field partial: every field marked *)
    $issueNote = 'مطلوب للإصدار';
    // the term choices in Arabic (12 شهرًا)
    $termOptions = collect(array_keys($terms))->mapWithKeys(fn ($months) => [$months => \Modules\ContractModule\app\Services\ContractService::term((int) $months)])->all();
    $date = fn ($field) => ($day = $value($field)) ? \Illuminate\Support\Carbon::parse($day)->format('Y-m-d') : null;
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $kind = $contract ? $contract->kind : $draft['kind'];
    $previous = $contract ? $contract->previous : $draft['previous'];
    $templateName = $contract ? ($contract->template?->name ?? $contract->template_name) : $draft['template']->name;
@endphp
@unless ($contract)
    <input type="hidden" name="reservation_id" value="{{ $reservation->id }}">
@endunless

<div class="contract-draft-head">
    <span class="badge {{ $kind === 'renewal' ? 'badge-status-info' : 'badge-status-pending' }}">{{ $kind === 'renewal' ? 'Renewal annex' : 'Contract' }}</span>
    @if ($previous)
        <span>renews contract <b>{{ $previous->number_label }}</b> ({{ $previous->starts_on->format($dateFormat) }} → {{ $previous->ends_on->format($dateFormat) }})</span>
    @endif
    <span class="text-slate-500">· template: {{ $templateName }}</span>
    <div class="mt-1 text-sm">
        <b>{{ $member?->name }}</b> <span class="text-slate-500">{{ $member?->phone }}</span> ·
        {{ $reservation->space?->name }} › {{ $reservation->unit?->name }} ·
        <span class="text-slate-500">reservation #{{ $reservation->id }}: {{ $reservation->start_at?->format($dateFormat) }} → {{ $reservation->realEnd()?->format($dateFormat) }}</span>
    </div>
</div>

<div class="form-section mt-6">بيانات العقد</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3" dir="rtl">
    @include('layoutmodule::partials.field', ['name' => 'unit_number', 'label' => 'رقم الوحدة', 'value' => $value('unit_number'), 'attrs' => 'dir="auto" maxlength="100"'])
    @include('layoutmodule::partials.field', ['name' => 'contract_date', 'label' => 'تاريخ التعاقد', 'type' => 'date', 'value' => $date('contract_date')])
    @include('layoutmodule::partials.field', ['name' => 'party_type', 'label' => 'نوع التعاقد', 'type' => 'select', 'required' => true,
        'options' => ['individual' => 'فرد', 'company' => 'شركة'], 'value' => $value('party_type') ?? 'individual'])
    @include('layoutmodule::partials.field', ['name' => 'starts_on', 'label' => 'بداية العقد', 'type' => 'date', 'required' => true, 'value' => $date('starts_on'),
        'attrs' => 'data-contract-term-start'])
    @include('layoutmodule::partials.field', ['name' => 'term_months', 'label' => 'المدة (بالشهور)', 'type' => 'select',
        'options' => ['' => 'مدة الحجز'] + $termOptions, 'value' => $value('term_months'), 'attrs' => 'data-contract-term-months'])
    @include('layoutmodule::partials.field', ['name' => 'ends_on', 'label' => 'نهاية العقد', 'type' => 'date', 'required' => true, 'value' => $date('ends_on'),
        'attrs' => 'data-contract-term-end data-reservation-end="' . $reservation->realEnd()?->format('Y-m-d') . '"',
        'hint' => 'تتبع البداية + المدة (يمكن تغييرها).'])
    @include('layoutmodule::partials.field', ['name' => 'duration_text', 'label' => 'مدة العقد', 'value' => $value('duration_text'),
        'attrs' => 'dir="auto" maxlength="100" data-contract-duration', 'hint' => 'كما تُكتب في العقد، مثلاً: سنة ميلادية'])
    @include('layoutmodule::partials.field', ['name' => 'contract_value', 'label' => 'قيمة العقد', 'type' => 'number',
        'value' => $contract ? $contract->term_amount : ($draft['term_amount'] ?? null),
        'attrs' => 'min="0" step="0.01" data-contract-value data-price="' . $reservation->dueAmount() . '" data-lease="' . $reservation->plan?->lease_period . '"',
        'hint' => 'الافتراضي: سعر الحجز عن المدة.'])
    @include('layoutmodule::partials.field', ['name' => 'currency', 'label' => 'العملة', 'value' => $value('currency'), 'attrs' => 'dir="auto" maxlength="50"'])
    @include('layoutmodule::partials.field', ['name' => 'deposit_amount', 'label' => 'التأمين', 'type' => 'number', 'value' => $value('deposit_amount'),
        'attrs' => 'min="0" step="0.01"', 'hint' => 'فارغ = بدون تأمين.'])
</div>

<div class="form-section mt-6">معلومات المستأجر</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3" dir="rtl">
    @include('layoutmodule::partials.field', ['name' => 'tenant_name', 'label' => 'اسم المستأجر', 'value' => $value('tenant_name'),
        'attrs' => 'dir="auto" maxlength="255"', 'hint' => $issueNote . ' (فرد)'])
    @include('layoutmodule::partials.field', ['name' => 'tenant_representative', 'label' => 'الممثل القانوني', 'value' => $value('tenant_representative'),
        'attrs' => 'dir="auto" maxlength="255"', 'hint' => $issueNote . ' (شركة)'])
    @include('layoutmodule::partials.field', ['name' => 'tenant_company', 'label' => 'اسم الشركة', 'value' => $value('tenant_company'),
        'attrs' => 'dir="auto" maxlength="255"', 'hint' => $issueNote . ' (شركة)'])
    @include('layoutmodule::partials.field', ['name' => 'tenant_title', 'label' => 'الصفة', 'value' => $value('tenant_title'), 'attrs' => 'dir="auto" maxlength="255"'])
    @include('layoutmodule::partials.field', ['name' => 'tenant_national_id', 'label' => 'الرقم القومي', 'value' => $value('tenant_national_id'),
        'attrs' => 'dir="ltr" maxlength="30" autocomplete="off"', 'hint' => $issueNote . ' (فرد)'])
    @include('layoutmodule::partials.field', ['name' => 'tenant_mobile', 'label' => 'رقم الهاتف المحمول', 'value' => $value('tenant_mobile'), 'attrs' => 'dir="ltr" maxlength="30"'])
    @include('layoutmodule::partials.field', ['name' => 'tenant_email', 'label' => 'البريد الإلكتروني', 'type' => 'email', 'value' => $value('tenant_email'), 'attrs' => 'dir="ltr" maxlength="255"'])
</div>
<div class="mt-4" dir="rtl">
    @include('layoutmodule::partials.checkbox', ['name' => 'save_to_member', 'label' => 'حفظ أيضاً في بيانات العضو / الشركة', 'checked' => false,
        'hint' => 'يملأ الحقول الفارغة فقط (الرقم القومي والبريد للعضو، الممثل القانوني والصفة للشركة)؛ لا يغيّر أي بيانات موجودة.'])
</div>

<div class="form-section mt-6">معلومات الوحدة</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2" dir="rtl">
    @include('layoutmodule::partials.field', ['name' => 'unit_address', 'label' => 'عنوان العين', 'type' => 'textarea', 'value' => $value('unit_address'),
        'attrs' => 'dir="auto" rows="2" maxlength="1000"'])
    @include('layoutmodule::partials.field', ['name' => 'activity', 'label' => 'النشاط بالوحدة', 'value' => $value('activity'), 'attrs' => 'dir="auto" maxlength="255"'])
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'value' => $value('notes'),
        'attrs' => 'dir="auto" rows="2" maxlength="2000"', 'hint' => 'تُطبع في جدول الوحدة.', 'wrapperClass' => 'md:col-span-2'])
</div>

{{-- this contract's wording (owner + employees): the template's text with its {placeholders}, edited here for this contract only
     (TinyMCE: custom.js contractEditors(), the preview with this contract's values; no chips here: user 2026-11-01;
     ContractService::setText() keeps the template's own text linked) --}}
<script type="application/json" data-contract-sample>@json((object) $sample)</script>
<span hidden data-contract-editor-base="{{ asset('assets/vendor/tinymce') }}"
    data-contract-editor-css="{{ asset('assets/css/pdf.css') }}?v={{ filemtime(public_path('assets/css/pdf.css')) }}"></span>
<div class="form-section mt-6">Contract Text</div>
<div class="mb-2 text-sm text-slate-500">
    From the template "{{ $templateName }}"@if ($contract && ($contract->body_ar || $contract->body_en)) <span class="badge badge-status-info">Text edited</span>@endif.
    Change the wording for <b>this contract only</b> (the template is not changed); the fields above fill the text when it is printed.
</div>
@foreach (['body_ar' => ['Arabic', 'rtl', true], 'body_en' => ['English', 'ltr', false]] as $field => [$language, $dir, $required])
    <div class="contract-editor mt-6">
        @include('layoutmodule::partials.field', ['name' => $field, 'label' => $language, 'type' => 'textarea', 'required' => $required,
            'value' => $texts[substr($field, -2)], 'attrs' => 'dir="' . $dir . '" data-contract-editor="' . substr($field, -2) . '"',
            'hint' => $field === 'body_en' ? 'Optional: the English PDF.' : null])
        <button type="button" class="contract-editor__preview-toggle" data-contract-preview-toggle="{{ $field }}"><i data-lucide="eye"></i> Preview with this reservation's values</button>
        <div class="contract-preview contract-body" dir="{{ $dir }}" data-contract-preview="{{ $field }}" hidden></div>
    </div>
@endforeach

@once
    @push('scripts')
        <script src="{{ asset('assets/vendor/tinymce/tinymce.min.js') }}?v={{ filemtime(public_path('assets/vendor/tinymce/tinymce.min.js')) }}"></script>
    @endpush
@endonce
