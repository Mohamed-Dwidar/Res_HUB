{{--
    A message text ($template: null = a new space version). Add: Type, Space, "Start from" (fills the texts: the type's default /
    a ready space text, config messagingmodule.space_texts). Arabic + English texts, each with its live preview (custom.js
    messagePreview(): $sample = the account's newest subscription, MessagingService::sampleVariables()); a placeholder chip
    inserts {name} where the cursor is.
--}}
<script type="application/json" data-message-sample>@json((object) $sample)</script>
@if (!$template)
    <script type="application/json" data-message-starts>@json($starts)</script>
    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
        @include('layoutmodule::partials.field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true,
            'options' => $types, 'value' => request('type')])
        @include('layoutmodule::partials.field', ['name' => 'space_id', 'label' => 'Space', 'type' => 'select', 'required' => true,
            'options' => ['' => '- Choose -'] + $spaces])
        @include('layoutmodule::partials.field', ['name' => 'start_from', 'label' => 'Start From', 'type' => 'select',
            'options' => ['' => '- Empty -'] + $startOptions, 'value' => 'default', 'attrs' => 'data-message-start',
            'hint' => 'Fills both texts (a ready text missing for this type = the default).'])
    </div>
@endif
<div class="mt-5">
    @include('layoutmodule::partials.checkbox', ['name' => 'is_enabled', 'label' => 'On', 'checked' => $template?->is_enabled ?? true,
        'hint' => $template?->isDefault() ? 'Off: this type is not offered (except in the spaces that have their own version on).' : 'Off: this type is not offered for this space.'])
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

<div class="form-section mt-6">Placeholders</div>
<div class="message-placeholders">
    @foreach (config('messagingmodule.placeholders') as $name => $description)
        <button type="button" class="message-placeholders__item" data-message-placeholder="{{ $name }}" title="{{ $description }}">{{ '{' . $name . '}' }}</button>
    @endforeach
</div>
<div class="mt-2 text-xs text-slate-500">Click one to insert it where the cursor is. Hover for what it is. *bold* and _italic_ work in WhatsApp.@if (empty($sample)) No reservation yet: the preview shows the placeholders.@endif</div>
