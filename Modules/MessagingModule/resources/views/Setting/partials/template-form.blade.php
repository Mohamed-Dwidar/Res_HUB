{{--
    A message ($template: null = new). "Start from" (add only; config messagingmodule.starts: fills the name when empty, "Suggest
    when" when Never, both texts), Name, "Suggest when" (optional), On, Arabic + English texts with their live preview (custom.js
    messagePreview(): $sample = the account's newest subscription, MessagingService::sampleVariables()), the units grouped by space
    (a space's checkbox = all its units; custom.js marks the units that already have another message with the chosen "Suggest
    when": $taken),
    placeholder chips (insert {name} where the cursor is).
--}}
@php($chosenUnits = array_map('intval', old('units', $template ? $template->units->pluck('id')->all() : [])))
<script type="application/json" data-message-sample>@json((object) $sample)</script>
<script type="application/json" data-message-taken>@json((object) $taken)</script>
@unless ($template)
    <script type="application/json" data-message-starts>@json($starts)</script>
@endunless
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    @unless ($template)
        @include('layoutmodule::partials.field', ['name' => 'start_from', 'label' => 'Start From', 'type' => 'select',
            'options' => ['' => '- Empty: write my own -'] + $startOptions, 'attrs' => 'data-message-start',
            'hint' => 'A ready text: fills both texts (+ the name and "Suggest when" when empty). Nothing is saved until you add it.'])
    @endunless
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $template?->name,
        'attrs' => 'maxlength="100" dir="auto"', 'hint' => 'What the employees see in the popup, e.g. "Virtual Office renewal".'])
    @include('layoutmodule::partials.field', ['name' => 'suggest_when', 'label' => 'Suggest When', 'type' => 'select',
        'options' => ['' => 'Never'] + $suggestOptions, 'value' => $template?->suggest_when,
        'hint' => 'Optional: ★ first in that list, and opened by itself after a new reservation / Mark left. One per unit for each choice.'])
</div>
<div class="mt-5">
    @include('layoutmodule::partials.checkbox', ['name' => 'is_enabled', 'label' => 'On', 'checked' => $template?->is_enabled ?? true,
        'hint' => 'Off: not offered for its units (they keep it, switch it on again any time).'])
</div>

<div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
    <div>
        @include('layoutmodule::partials.field', ['name' => 'body_ar', 'label' => 'Arabic Text', 'type' => 'textarea', 'required' => true,
            'value' => $template?->body_ar, 'attrs' => 'dir="rtl" maxlength="2000" data-message-body="ar"'])
        <div class="message-preview__label">Preview</div>
        <div class="message-preview" dir="rtl" data-message-preview="ar"></div>
    </div>
    <div>
        @include('layoutmodule::partials.field', ['name' => 'body_en', 'label' => 'English Text', 'type' => 'textarea',
            'value' => $template?->body_en, 'attrs' => 'dir="ltr" maxlength="2000" data-message-body="en"', 'hint' => 'Empty = the Arabic text is sent to English members too.'])
        <div class="message-preview__label">Preview</div>
        <div class="message-preview" dir="ltr" data-message-preview="en"></div>
    </div>
</div>

<div class="form-section mt-6">Units <span class="message-units-count" data-message-units-count></span></div>
<div data-field="units" data-message-units>
    @forelse ($spaces as $space)
        <div class="message-unit-space">
            <div class="message-unit-space__head">
                <input type="checkbox" id="message-space-{{ $space->id }}" class="{{ config('layoutmodule.form.checkbox') }}" data-message-space>
                <label for="message-space-{{ $space->id }}" class="{{ config('layoutmodule.form.checkbox_label') }} font-medium">{{ $space->name }}</label>
                <span class="message-unit-space__count" data-message-space-count></span>
            </div>
            <div class="message-unit-space__units">
                @foreach ($space->units as $unit)
                    <div class="message-unit" data-message-unit="{{ $unit->id }}">
                        <input type="checkbox" name="units[]" id="message-unit-{{ $unit->id }}" value="{{ $unit->id }}" class="{{ config('layoutmodule.form.checkbox') }}"
                            @checked(in_array($unit->id, $chosenUnits, true))>
                        <label for="message-unit-{{ $unit->id }}" class="{{ config('layoutmodule.form.checkbox_label') }}">
                            {{ $unit->name }}@unless ($unit->is_active) <span class="text-xs text-slate-400">(Inactive)</span>@endunless
                            <span class="message-unit__taken" data-message-taken-note hidden></span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="text-slate-500">No units yet: add them from Spaces &amp; Units.</div>
    @endforelse
    <div class="{{ config('layoutmodule.form.hint') }}">Tick a space for all its units, or the units one by one. A unit can have one message for each "Suggest when" (Never: any number): a unit marked in orange already has another message with the chosen one. Units added later get no message until you tick them.</div>
</div>

<div class="form-section mt-6">Placeholders</div>
<div class="message-placeholders">
    @foreach (config('messagingmodule.placeholders') as $name => $description)
        <button type="button" class="message-placeholders__item" data-message-placeholder="{{ $name }}" title="{{ $description }}">{{ '{' . $name . '}' }}</button>
    @endforeach
</div>
<div class="mt-2 text-xs text-slate-500">Click one to insert it where the cursor is. Hover for what it is. *bold* and _italic_ work in WhatsApp.@if (empty($sample)) No reservation yet: the preview shows the placeholders.@endif</div>
