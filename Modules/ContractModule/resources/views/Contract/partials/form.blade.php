{{--
    A contract draft page (Create / Renew contract: $draft from ContractService::draftFor(), $contract null; Edit draft: $contract).
    $reservation = the period it is made from. 2026-11-01 (user): ALL the printed data is the contract's own (like the old DMS form),
    pre-filled from the reservation / member / company / Settings › Contracts (a renewal: from the previous contract) and edited
    here freely: contract data | بيانات المؤجر (2026-11-25) | معلومات المستأجر | معلومات الوحدة (Arabic labels, right to left). Never
    written to the member unless "Also save to member" (its empty fields only). The end, the duration and the value (before tax)
    follow the start + term while untouched (custom.js contractTermEnd()). Required (2026-11-25): every field except
    the tenant's email and title, the deposit and the notes (Contract::REQUIRED_FIELDS); commercial register / tax card: a company only.
--}}
@php
    $member = $reservation->member;
    $value = fn ($field) => $contract ? $contract->{$field} : ($draft[$field] ?? null);
    // the term choices in Arabic (12 شهرًا)
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
    $reservationMonths = $contractService::leaseMonths($reservation, $reservation->start_at, $reservation->realEnd());
    // the saved term, else the subscription's: a year / a month
    $termMonths = $value('term_months') ?: $reservationMonths;
    $termOptions = collect(array_keys($terms))->push($termMonths)->filter()->unique()->sort()->mapWithKeys(fn ($months) => [$months => \Modules\ContractModule\app\Services\ContractService::term((int) $months)])->all();
    $date = fn ($field) => ($day = $value($field)) ? \Illuminate\Support\Carbon::parse($day)->format('Y-m-d') : null;
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $kind = $contract ? $contract->kind : $draft['kind'];
    $previous = $contract ? $contract->previous : $draft['previous'];
    // an old company draft: its company name is the tenant name now (2026-11-25)
    $tenantName = $contract && $contract->party_type === 'company' && $contract->tenant_company ? $contract->tenant_company : $value('tenant_name');
    $lessor = $contractService->lessorDefaults((int) $reservation->account_id);
    // a shared unit (Virtual Office): the number is needed to issue
    $sharedUnit = (int) $reservation->unit?->concurrent_usage > 1;
    // a renewal annex (user 2026-11-25): the original contract's data + the renewal's period / amount, read only (ContractService::annexData())
    $locked = $kind === 'renewal';
    $ro = $locked ? ' readonly tabindex="-1"' : '';
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

@if ($locked)
    {{-- a renewal annex (user 2026-11-25): only its period and amount are shown; the lessor, lessee and unit come from the original
         contract and are not edited here (ContractService::annexData()); its date = the issue date --}}
    @php
        $original = $contractService::originalOf($previous) ?? $previous;
        $annexValues = $contractService->annexPreview($reservation, $contract, $draft ?? null);
    @endphp
    <div class="notice mt-4" dir="rtl">ملحق تجديد للعقد الأصلي {{ $original?->number_label }}: البيانات من العقد الأصلي، والمدة والقيمة من الحجز، وتاريخ الملحق = تاريخ الإصدار.</div>
    {{-- the annex text with this renewal's values filled in, editable for this annex only (user 2026-11-25; saved as it is:
         ContractService::setText(); the template is not changed) --}}
    <span hidden data-contract-editor-base="{{ asset('assets/vendor/tinymce') }}"
        data-contract-editor-css="{{ asset('assets/css/pdf.css') }}?v={{ filemtime(public_path('assets/css/pdf.css')) }}"></span>
    <script type="application/json" data-contract-sample>{}</script>
    <div class="form-section mt-6">نص الملحق</div>
    <div class="mb-2 text-sm text-slate-500" dir="rtl">النص بقيم هذا التجديد؛ يمكن تعديله لهذا الملحق فقط (القالب لا يتغير).</div>
    <div class="contract-editor">
        @include('layoutmodule::partials.field', ['name' => 'body_ar', 'label' => 'Arabic', 'type' => 'textarea', 'required' => true,
            'value' => $contractService::render($texts['ar'], $annexValues), 'attrs' => 'dir="rtl" data-contract-editor="ar"'])
    </div>
@else
<div class="form-section mt-6">بيانات العقد</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3" dir="rtl">
    {{-- a numbered unit (its prefix / first number, 2026-11-25): the lowest free number is suggested; only the owner changes it --}}
    @php
        $suggestedNumber = $contractService->suggestNumber($reservation->unit);
        $numberLocked = $suggestedNumber !== null && !$contractService::isOwner(Auth::user());
        $isSuggested = $suggestedNumber !== null && !$contract && $value('unit_number') === $suggestedNumber;
    @endphp
    @include('layoutmodule::partials.field', ['name' => 'unit_number', 'label' => 'رقم الوحدة' . ($isSuggested ? ' · Suggested' : ''), 'required' => true, 'value' => $value('unit_number'),
        'attrs' => 'dir="auto" maxlength="100" placeholder="V 138"' . ($numberLocked ? ' readonly tabindex="-1"' : $ro),
        'hint' => $suggestedNumber !== null ? ($numberLocked ? 'الرقم المقترح (يغيّره المالك فقط).' : 'أقل رقم متاح في الوحدة؛ يمكن كتابة أي رقم متاح.') : ($sharedUnit ? 'رقم المكان في الوحدة، مثلاً V 138' : null)])
    @include('layoutmodule::partials.field', ['name' => 'contract_date', 'label' => 'تاريخ التعاقد', 'required' => !$locked, 'type' => 'date', 'value' => $date('contract_date'), 'hint' => $locked ? 'فارغ = تاريخ الإصدار.' : 'الافتراضي: اليوم.'])
    @include('layoutmodule::partials.field', ['name' => 'party_type', 'label' => 'نوع التعاقد', 'type' => 'select', 'required' => true,
        'options' => ['individual' => 'فرد', 'company' => 'شركة'], 'value' => $value('party_type') ?? 'individual', 'attrs' => $locked ? 'disabled' : ''])
    @include('layoutmodule::partials.field', ['name' => 'starts_on', 'label' => 'بداية العقد', 'type' => 'date', 'required' => true, 'value' => $date('starts_on'),
        'attrs' => 'data-contract-term-start' . $ro])
    @include('layoutmodule::partials.field', ['name' => 'term_months', 'label' => 'المدة', 'type' => 'select',
        'options' => ($reservationMonths ? [] : ['' => 'مدة الحجز']) + $termOptions, 'value' => $termMonths, 'attrs' => ($locked ? 'disabled ' : '') . 'data-contract-term-months'])
    @include('layoutmodule::partials.field', ['name' => 'ends_on', 'label' => 'نهاية العقد', 'type' => 'date', 'required' => true, 'value' => $date('ends_on'),
        'attrs' => 'data-contract-term-end data-reservation-end="' . $reservation->realEnd()?->format('Y-m-d') . '" data-reservation-start="' . $reservation->start_at?->format('Y-m-d') . '" data-reservation-months="' . $reservationMonths . '"' . $ro,
        'hint' => $locked ? null : 'تتبع البداية + المدة (يمكن تغييرها).'])
    @include('layoutmodule::partials.field', ['name' => 'duration_text', 'label' => 'مدة العقد', 'required' => true, 'value' => $value('duration_text'),
        'attrs' => 'dir="auto" maxlength="100" data-contract-duration data-reservation-duration="' . ($reservationMonths ? $contractService::term($reservationMonths) : '') . '"' . $ro, 'hint' => 'كما تُكتب في العقد، مثلاً: سنة ميلادية'])
    @include('layoutmodule::partials.field', ['name' => 'contract_value', 'label' => 'قيمة العقد', 'required' => true, 'type' => 'number',
        'value' => $contract ? $contract->term_amount : ($draft['term_amount'] ?? null),
        'attrs' => 'min="0" step="0.01" data-contract-value data-price="' . $contractService::netPrice($reservation) . '" data-reservation-months="' . $reservationMonths . '"' . $ro,
        'hint' => 'الافتراضي: سعر الحجز عن المدة قبل الضريبة.'])
    @include('layoutmodule::partials.field', ['name' => 'currency', 'label' => 'العملة', 'required' => true, 'value' => $value('currency'), 'attrs' => 'dir="auto" maxlength="50"' . $ro])
    @include('layoutmodule::partials.field', ['name' => 'deposit_amount', 'label' => 'التأمين', 'type' => 'number', 'value' => $value('deposit_amount'),
        'attrs' => 'min="0" step="0.01"' . $ro, 'hint' => 'فارغ = بدون تأمين.'])
</div>

{{-- the lessor on this contract (pre-filled from Settings › Contracts; NULL = never set) --}}
<div class="form-section mt-6">بيانات المؤجر</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3" dir="rtl">
    @foreach (['lessor_name' => ['المؤجر', 255, 'auto'], 'lessor_commercial_register' => ['سجل تجاري', 50, 'auto'], 'lessor_tax_number' => ['رقم التسجيل الضريبي', 50, 'ltr'],
        'lessor_representative' => ['الممثل القانوني', 255, 'auto'], 'lessor_representative_title' => ['صفته', 255, 'auto'],
        'lessor_representative_national_id' => ['بطاقة الرقم القومي', 30, 'ltr']] as $field => [$label, $max, $dir])
        @include('layoutmodule::partials.field', ['name' => $field, 'label' => $label, 'required' => true, 'value' => $value($field) ?? $lessor[$field],
            'attrs' => 'dir="' . $dir . '" maxlength="' . $max . '"' . $ro, 'hint' => null])
    @endforeach
</div>

<div class="form-section mt-6">معلومات المستأجر</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3" dir="rtl">
    @include('layoutmodule::partials.field', ['name' => 'tenant_name', 'label' => 'اسم المستأجر (فرد / شركة)', 'required' => true, 'value' => $tenantName,
        'attrs' => 'dir="auto" maxlength="255"' . $ro])
    @include('layoutmodule::partials.field', ['name' => 'tenant_representative', 'label' => 'الممثل القانوني', 'required' => true, 'value' => $value('tenant_representative'),
        'attrs' => 'dir="auto" maxlength="255"' . $ro])
    @include('layoutmodule::partials.field', ['name' => 'tenant_title', 'label' => 'الصفة', 'value' => $value('tenant_title'), 'attrs' => 'dir="auto" maxlength="255"' . $ro, 'hint' => null])
    @include('layoutmodule::partials.field', ['name' => 'tenant_national_id', 'label' => 'الرقم القومي', 'required' => true, 'value' => $value('tenant_national_id'),
        'attrs' => 'dir="ltr" maxlength="30" autocomplete="off"' . $ro])
    {{-- a company only --}}
    <div data-show-if="party_type=company">
        @include('layoutmodule::partials.field', ['name' => 'tenant_commercial_register', 'label' => 'سجل تجاري', 'required' => true, 'value' => $value('tenant_commercial_register'),
            'attrs' => 'dir="auto" maxlength="50"' . $ro])
    </div>
    <div data-show-if="party_type=company">
        @include('layoutmodule::partials.field', ['name' => 'tenant_tax_number', 'label' => 'رقم البطاقة الضريبية', 'required' => true, 'value' => $value('tenant_tax_number'),
            'attrs' => 'dir="ltr" maxlength="50"' . $ro])
    </div>
    @include('layoutmodule::partials.field', ['name' => 'tenant_mobile', 'label' => 'رقم الهاتف المحمول', 'required' => true, 'value' => $value('tenant_mobile'), 'attrs' => 'dir="ltr" maxlength="100"' . $ro])
    @include('layoutmodule::partials.field', ['name' => 'tenant_email', 'label' => 'البريد الإلكتروني', 'type' => 'email', 'value' => $value('tenant_email'), 'attrs' => 'dir="ltr" maxlength="255"' . $ro])
</div>
<div class="mt-4" dir="rtl" @if ($locked) hidden @endif>
    @include('layoutmodule::partials.checkbox', ['name' => 'save_to_member', 'label' => 'حفظ أيضاً في بيانات العضو', 'checked' => false,
        'hint' => 'يملأ حقول العضو الفارغة فقط (الاسم القانوني، الممثل القانوني، الصفة، الرقم القومي، السجل، البطاقة الضريبية، البريد، النشاط)؛ لا يغيّر أي بيانات موجودة.'])
</div>

<div class="form-section mt-6">معلومات الوحدة</div>
<div class="grid grid-cols-1 gap-5" dir="rtl">
    @include('layoutmodule::partials.field', ['name' => 'unit_address', 'label' => 'عنوان العين', 'required' => true, 'value' => $value('unit_address'), 'width' => 'full',
        'attrs' => 'dir="auto" maxlength="1000"' . $ro])
    {{-- the member's notes are shown only (internal: V numbers, phones …), never printed by themselves --}}
    @include('layoutmodule::partials.field', ['name' => 'activity', 'label' => 'النشاط بالوحدة', 'required' => true, 'type' => 'textarea', 'value' => $value('activity'), 'attrs' => 'dir="auto" rows="2" maxlength="500"' . $ro,
        'hint' => trim((string) $member?->notes) !== '' ? 'ملاحظات العضو (لا تُطبع): ' . $member->notes : null])
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'value' => $value('notes'),
        'attrs' => 'dir="auto" rows="2" maxlength="2000"', 'hint' => 'تُطبع في جدول الوحدة.'])
</div>

@endif

@unless ($locked)
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
@endunless

@once
    @push('scripts')
        <script src="{{ asset('assets/vendor/tinymce/tinymce.min.js') }}?v={{ filemtime(public_path('assets/vendor/tinymce/tinymce.min.js')) }}"></script>
    @endpush
@endonce
