{{--
    The unit form's "WhatsApp Messages" section (UnitModule Account/Unit/partials/form, 2026-10-31): the account's messages by name
    with their "Suggest when", ticked = sent for this unit's reservations (the same assignment as the message form's units). One per
    "Suggest when" (Never: any number): the save refuses two (MessagingService::unitTemplatesError()). Sends message_templates[]
    (+ the marker message_templates_sent, so "none ticked" clears them). $unit: null on add.
--}}
@php($messageForm = app(\Modules\MessagingModule\app\Services\MessagingService::class)->unitFormData(Auth::user()->userable->ownerAccountId(), $unit?->id))
@php($chosenMessages = array_map('intval', old('message_templates', $messageForm['selected'])))
<div class="form-section mt-8">WhatsApp Messages</div>
<div data-field="message_templates">
    <input type="hidden" name="message_templates_sent" value="1">
    @if ($messageForm['templates']->isEmpty())
        <div class="text-slate-500">No messages yet: add them in <a href="{{ route('account.settings.messages.edit') }}" class="text-primary">Settings › WhatsApp Messages</a>.</div>
    @else
        <div class="message-unit-list">
            @foreach ($messageForm['templates'] as $template)
                <div class="flex items-center">
                    <input type="checkbox" name="message_templates[]" id="unit-message-{{ $template->id }}" value="{{ $template->id }}" class="{{ config('layoutmodule.form.checkbox') }}"
                        @checked(in_array($template->id, $chosenMessages, true))>
                    <label for="unit-message-{{ $template->id }}" class="{{ config('layoutmodule.form.checkbox_label') }}">
                        {{ $template->name }}
                        <span class="text-xs text-slate-500">· {{ $template->suggest_when ? 'Suggest when: ' . $template->suggest_label : 'Never suggested' }}@unless ($template->is_enabled) · Off @endunless</span>
                    </label>
                </div>
            @endforeach
        </div>
        <div class="{{ config('layoutmodule.form.hint') }}">Tick the messages for this unit's reservations, one for each "Suggest when" at most (Never: any number). None ticked = no WhatsApp icon on its reservations.</div>
    @endif
</div>
