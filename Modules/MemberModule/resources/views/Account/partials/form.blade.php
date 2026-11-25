{{-- Member create / edit fields. $member is null on create. --}}
<div class="form-section">Member Data</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $member?->name, 'attrs' => 'autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'value' => $member?->phone, 'attrs' => 'inputmode="tel" pattern="\+?[0-9 ]{8,30}" placeholder="01012345678"', 'hint' => 'Spaces are removed automatically.'])
    @include('layoutmodule::partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $member?->email])
    {{-- the language of the WhatsApp messages (MessagingModule): the popup opens in it, empty = Settings › WhatsApp Messages' default --}}
    @include('layoutmodule::partials.field', ['name' => 'message_language', 'label' => 'WhatsApp Language', 'type' => 'select',
        'options' => ['' => 'Account default'] + config('messagingmodule.languages'), 'value' => $member?->message_language,
        'hint' => 'The language the WhatsApp messages open in (it can still be switched in the message popup).'])
</div>

{{-- for contracts (ContractModule, 2026-10-31): an individual's contract needs the ID number --}}
<div class="form-section mt-8">Identity</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'id_type', 'label' => 'ID Type', 'type' => 'select',
        'options' => \Modules\MemberModule\app\Models\Member::ID_TYPES, 'value' => $member?->id_type ?: 'national_id'])
    @include('layoutmodule::partials.field', ['name' => 'national_number', 'label' => 'ID Number', 'value' => $member?->national_number,
        'attrs' => 'maxlength="20" autocomplete="off"', 'hint' => 'National ID: 14 digits. Passport: letters and digits. Needed to issue a contract.'])
    @include('layoutmodule::partials.field', ['name' => 'nationality', 'label' => 'Nationality', 'value' => $member?->nationality, 'attrs' => 'dir="auto" maxlength="100"'])
    @include('layoutmodule::partials.field', ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'value' => $member?->address, 'attrs' => 'dir="auto" rows="2" maxlength="1000"'])
</div>

{{-- the lessee of a contract (2026-11-25): printed on it, editable per contract --}}
<div class="form-section mt-8">Contract Data</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'legal_name', 'label' => 'Legal Name', 'value' => $member?->legal_name,
        'attrs' => 'dir="auto" maxlength="255"', 'hint' => 'The person or company name on the contract. Empty = the member\'s name.'])
    @include('layoutmodule::partials.field', ['name' => 'representative_name', 'label' => 'Legal Representative', 'value' => $member?->representative_name,
        'attrs' => 'dir="auto" maxlength="255"', 'hint' => 'Empty = the member\'s name. The ID Number above is his / hers.'])
    @include('layoutmodule::partials.field', ['name' => 'representative_title', 'label' => 'Title (الصفة)', 'value' => $member?->representative_title, 'attrs' => 'dir="auto" maxlength="255"'])
    @include('layoutmodule::partials.field', ['name' => 'other_phone', 'label' => 'Other Mobile', 'type' => 'tel', 'value' => $member?->other_phone, 'attrs' => 'inputmode="tel" maxlength="30"',
        'hint' => 'Printed after the phone on the contract.'])
    @include('layoutmodule::partials.field', ['name' => 'commercial_register', 'label' => 'Commercial Register', 'value' => $member?->commercial_register, 'attrs' => 'dir="auto" maxlength="50"'])
    @include('layoutmodule::partials.field', ['name' => 'tax_number', 'label' => 'Tax Card No.', 'value' => $member?->tax_number, 'attrs' => 'maxlength="50"'])
    @include('layoutmodule::partials.field', ['name' => 'activity', 'label' => 'Activity', 'type' => 'textarea', 'value' => $member?->activity,
        'attrs' => 'dir="auto" rows="2" maxlength="500"', 'hint' => 'النشاط بالوحدة on the contract.', 'wrapperClass' => 'md:col-span-2'])
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
