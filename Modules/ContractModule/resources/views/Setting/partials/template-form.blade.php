{{--
    A contract template ($template: null = new; a full page; the owner writes every text, no ready-made wording). $copy (Duplicate,
    new only): an unsaved template whose name / titles / texts / term fill the form; never its units (they start empty). Name, Term, On, titles, the contract text + the renewal annex in Arabic / English
    (rich text: TinyMCE 4.7.13, public/assets/vendor/tinymce, custom.js contractEditors(); the same allowlist as the server's
    ContractService::sanitize()), each with a preview of the sample values ($sample = the account's newest subscription,
    ContractService::sampleVariables()), the placeholder chips (insert {name} in the editor used last), the units grouped by space
    (a space's checkbox = all its units; $taken = units of other templates, marked: one template per unit).
--}}
@php($chosenUnits = array_map('intval', old('units', $template ? $template->units->pluck('id')->all() : [])))
@php($fill = $template ?? ($copy ?? null))
<script type="application/json" data-contract-sample>@json((object) $sample)</script>
<script type="application/json" data-contract-taken>@json((object) $taken)</script>
<span hidden data-contract-editor-base="{{ asset('assets/vendor/tinymce') }}"
    data-contract-editor-css="{{ asset('assets/css/pdf.css') }}?v={{ filemtime(public_path('assets/css/pdf.css')) }}"></span>

<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $fill?->name,
        'attrs' => 'maxlength="100" dir="auto"', 'hint' => 'What you and the employees see, e.g. "Private office rental".'])
    @include('layoutmodule::partials.field', ['name' => 'default_term_months', 'label' => 'Contract Term', 'type' => 'select',
        'options' => ['' => 'The reservation\'s period'] + $terms, 'value' => $fill?->default_term_months,
        'hint' => 'How long a contract runs; monthly renewals inside it need no new contract. 12 months for monthly units.'])
</div>
<div class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'title_ar', 'label' => 'Title (Arabic)', 'required' => true, 'value' => $fill?->title_ar,
        'attrs' => 'dir="rtl" maxlength="255"', 'hint' => 'Printed at the top of the contract.'])
    @include('layoutmodule::partials.field', ['name' => 'title_en', 'label' => 'Title (English)', 'value' => $fill?->title_en,
        'attrs' => 'maxlength="255"', 'hint' => 'For the English contract.'])
</div>
<div class="mt-5">
    @include('layoutmodule::partials.checkbox', ['name' => 'is_enabled', 'label' => 'On', 'checked' => $template?->is_enabled ?? true,
        'hint' => 'Off: no new contracts from it (its units keep it, switch it on again any time).'])
</div>

{{-- the chips stay in view while the long texts scroll --}}
<div class="contract-placeholders">
    <div class="contract-placeholders__head">Placeholders <span>click one to insert it where the cursor is in the text you used last · hover for what it is</span></div>
    @foreach (config('contractmodule.placeholders') as $group => $items)
        <div class="contract-placeholders__group">
            <span class="contract-placeholders__label">{{ $group }}</span>
            @foreach ($items as $name => $description)
                <button type="button" class="message-placeholders__item" data-contract-placeholder="{{ $name }}" title="{{ $description }}">{{ '{' . $name . '}' }}</button>
            @endforeach
        </div>
    @endforeach
    @if (empty($sample))
        <div class="mt-1 text-xs text-slate-500">No reservation yet: the preview shows the placeholders.</div>
    @endif
</div>

@foreach ([
    'Contract Text' => ['body_ar' => ['Arabic', 'rtl', true], 'body_en' => ['English', 'ltr', false]],
    'Renewal Annex' => ['renewal_body_ar' => ['Arabic', 'rtl', false], 'renewal_body_en' => ['English', 'ltr', false]],
] as $section => $fields)
    <div class="form-section mt-8">{{ $section }}</div>
    @if ($section === 'Renewal Annex')
        <div class="mb-3 text-sm text-slate-500">Printed when a contract is renewed for another term (a short text that refers to the first contract: {previous_contract_no}, {previous_contract_date}). Empty = renewals print the full contract text again.</div>
    @else
        <div class="mb-3 text-sm text-slate-500">The clauses. The contract number, the parties, the unit table and the signatures are printed around this text by the system. Write each clause title as "Clause title" (in the paragraph list) with its number in the text.</div>
    @endif
    @foreach ($fields as $field => [$language, $dir, $required])
        <div class="contract-editor">
            @include('layoutmodule::partials.field', ['name' => $field, 'label' => $language, 'type' => 'textarea', 'required' => $required,
                'value' => $fill?->{$field}, 'attrs' => 'dir="' . $dir . '" data-contract-editor="' . substr($field, -2) . '"',
                'hint' => $field === 'body_en' ? 'Optional: an English contract when the member asks for one.' : null])
            <button type="button" class="contract-editor__preview-toggle" data-contract-preview-toggle="{{ $field }}"><i data-lucide="eye"></i> Preview with sample data</button>
            <div class="contract-preview contract-body" dir="{{ $dir }}" data-contract-preview="{{ $field }}" hidden></div>
        </div>
    @endforeach
@endforeach

<div class="form-section mt-8">Units <span class="message-units-count" data-contract-units-count></span></div>
<div data-field="units" data-contract-units>
    @forelse ($spaces as $space)
        <div class="message-unit-space">
            <div class="message-unit-space__head">
                <input type="checkbox" id="contract-space-{{ $space->id }}" class="{{ config('layoutmodule.form.checkbox') }}" data-contract-space>
                <label for="contract-space-{{ $space->id }}" class="{{ config('layoutmodule.form.checkbox_label') }} font-medium">{{ $space->name }}</label>
                <span class="message-unit-space__count" data-contract-space-count></span>
            </div>
            <div class="message-unit-space__units">
                @foreach ($space->units as $unit)
                    <div class="message-unit" data-contract-unit="{{ $unit->id }}">
                        <input type="checkbox" name="units[]" id="contract-unit-{{ $unit->id }}" value="{{ $unit->id }}" class="{{ config('layoutmodule.form.checkbox') }}"
                            @checked(in_array($unit->id, $chosenUnits, true))>
                        <label for="contract-unit-{{ $unit->id }}" class="{{ config('layoutmodule.form.checkbox_label') }}">
                            {{ $unit->name }}@unless ($unit->is_active) <span class="text-xs text-slate-400">(Inactive)</span>@endunless
                            <span class="message-unit__taken" data-contract-taken-note hidden></span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="text-slate-500">No units yet: add them from Spaces &amp; Units.</div>
    @endforelse
    <div class="{{ config('layoutmodule.form.hint') }}">Tick a space for all its units, or the units one by one. A unit has one contract template: a unit marked in orange already has another one (untick it there first). Units added later get no template until you tick them.</div>
</div>

@once
    @push('scripts')
        <script src="{{ asset('assets/vendor/tinymce/tinymce.min.js') }}?v={{ filemtime(public_path('assets/vendor/tinymce/tinymce.min.js')) }}"></script>
    @endpush
@endonce
