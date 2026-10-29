{{-- Member create / edit fields. $member is null on create. --}}
<div class="form-section">Member Data</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $member?->name, 'attrs' => 'autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'value' => $member?->phone, 'attrs' => 'inputmode="tel" pattern="\+?[0-9 ]{8,30}" placeholder="01012345678"', 'hint' => 'Spaces are removed automatically.'])
    @include('layoutmodule::partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $member?->email])
    @include('layoutmodule::partials.field', ['name' => 'national_number', 'label' => 'National Number', 'value' => $member?->national_number, 'attrs' => 'inputmode="numeric" pattern="[0-9]{5,15}"'])
    {{-- the language of the WhatsApp messages (MessagingModule): the popup opens in it, empty = Settings › WhatsApp Messages' default --}}
    @include('layoutmodule::partials.field', ['name' => 'message_language', 'label' => 'WhatsApp Language', 'type' => 'select',
        'options' => ['' => 'Account default'] + config('messagingmodule.languages'), 'value' => $member?->message_language,
        'hint' => 'The language the WhatsApp messages open in (it can still be switched in the message popup).'])
</div>

<div class="form-section mt-8">Work</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'company_id', 'label' => 'Company', 'type' => 'select', 'options' => ['' => '- None -'] + $companies, 'value' => $member?->company_id ?: null,
        'hint' => empty($companies) ? 'No companies yet, add them from Companies.' : null])
    @include('layoutmodule::partials.field', ['name' => 'job_id', 'label' => 'Job', 'type' => 'select', 'options' => ['' => '- None -'] + $jobs, 'value' => $member?->job_id ?: null,
        'hint' => empty($jobs) ? 'No jobs yet, add them from Jobs.' : null])
</div>

<div class="form-section mt-8">More</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'from_where', 'label' => 'Source (from where)', 'value' => $member?->from_where, 'hint' => 'How did the member hear about you?'])
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $member?->notes])
</div>
