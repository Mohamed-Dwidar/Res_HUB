{{-- Company create / edit fields. $company is null on create. --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'required' => true, 'value' => $company?->name_ar, 'attrs' => 'dir="rtl" autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $company?->name_en])
</div>

{{-- for contracts (ContractModule, 2026-10-31): a company's contract needs the commercial register + the representative's name --}}
<div class="form-section mt-8">Legal Data</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'commercial_register', 'label' => 'Commercial Register', 'value' => $company?->commercial_register, 'attrs' => 'maxlength="50"'])
    @include('layoutmodule::partials.field', ['name' => 'tax_number', 'label' => 'Tax Registration Number', 'value' => $company?->tax_number, 'attrs' => 'maxlength="50"'])
    @include('layoutmodule::partials.field', ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'value' => $company?->address, 'attrs' => 'dir="auto" rows="2" maxlength="1000"', 'wrapperClass' => 'md:col-span-2'])
    @include('layoutmodule::partials.field', ['name' => 'representative_name', 'label' => 'Legal Representative', 'value' => $company?->representative_name, 'attrs' => 'dir="auto" maxlength="255"', 'hint' => 'Signs the contract for the company.'])
    @include('layoutmodule::partials.field', ['name' => 'representative_title', 'label' => 'Representative\'s Title', 'value' => $company?->representative_title, 'attrs' => 'dir="auto" maxlength="255"'])
    @include('layoutmodule::partials.field', ['name' => 'representative_national_id', 'label' => 'Representative\'s National ID', 'value' => $company?->representative_national_id, 'attrs' => 'maxlength="30"'])
</div>
