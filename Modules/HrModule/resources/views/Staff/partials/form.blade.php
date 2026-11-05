{{--
    Staff file fields (add / edit popup) = the employees row (Staff). $staff null on create; $departments / $positions / $shifts / $roles = [id => name];
    $sensitive = hr.sensitive (the Identity / Pay / Emergency sections; without it they are not shown and not saved);
    External (is_external = 1) hides Work / Identity / Pay / Emergency; System Access = partials/access-fields.
--}}
@php($f = fn ($field, $default = null) => $staff?->{$field} ?? $default)
<div class="form-grid">
    @include('layoutmodule::partials.field', ['name' => 'is_external', 'label' => 'Type', 'type' => 'select', 'wrapperClass' => 'form-grid__full',
        'options' => ['0' => 'Employee (on payroll)', '1' => 'External (not on payroll)'], 'value' => $staff?->is_external ? '1' : '0',
        'hint' => 'External: e.g. the accounting office — no payroll, not counted in HR; only name, mobile and email needed.'])
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $f('name'), 'attrs' => 'autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'value' => $f('name_ar'), 'attrs' => 'dir=rtl'])
    @include('layoutmodule::partials.field', ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true,
        'options' => \Modules\EmployeeModule\app\Models\Employee::GENDERS, 'value' => $f('gender', 'male')])
    @include('layoutmodule::partials.field', ['name' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'value' => $f('phone'), 'attrs' => 'inputmode="tel"'])
    @include('layoutmodule::partials.field', ['name' => 'another_phone', 'label' => 'Another Phone', 'type' => 'tel', 'value' => $f('another_phone'), 'attrs' => 'inputmode="tel"'])
    @include('layoutmodule::partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $f('email')])
</div>

<div data-show-if="is_external=0">
<div class="form-section mt-8">Work</div>
<div class="form-grid">
    {{-- Department → Job Title: the department only filters the titles (its titles + those with no department; a change empties the
         title); the employee's department = the title's. No departments = no Department field. --}}
    @if ($departments)
        @include('layoutmodule::partials.field', ['name' => 'department_id', 'label' => 'Department', 'type' => 'select',
            'options' => ['' => 'General'] + $departments, 'value' => $staff?->position?->department_id ?: null])
    @endif
    @include('layoutmodule::partials.field', ['name' => 'position_id', 'label' => 'Job Title', 'type' => 'select', 'required' => true,
        'options' => ['' => '- Choose -'] + $positions, 'value' => $staff?->position_id ?: null,
        'attrs' => $departments ? 'data-filter-parents="department_id" data-filter-reset' : '',
        'hint' => empty($positions) ? 'Add job titles in HR › Employee Positions.' : null])
    @include('layoutmodule::partials.field', ['name' => 'shift_id', 'label' => 'Usual Shift', 'type' => 'select',
        'options' => ['' => '- None -'] + $shifts, 'value' => $staff?->shift_id ?: null])
    @include('layoutmodule::partials.field', ['name' => 'join_date', 'label' => 'Hire Date', 'type' => 'date', 'value' => $staff?->join_date?->format('Y-m-d')])
    @include('layoutmodule::partials.field', ['name' => 'probation_ends_on', 'label' => 'Probation Ends', 'type' => 'date', 'value' => $staff?->probation_ends_on?->format('Y-m-d')])
</div>

@if ($sensitive)
    <div class="form-section mt-8">Identity</div>
    <div class="form-grid">
        @include('layoutmodule::partials.field', ['name' => 'id_type', 'label' => 'ID Type', 'type' => 'select', 'required' => true,
            'options' => config('hrmodule.id_types'), 'value' => $f('id_type', 'national_id')])
        @include('layoutmodule::partials.field', ['name' => 'national_id', 'label' => 'ID Number', 'value' => $f('national_id'), 'attrs' => 'inputmode="numeric" autocomplete="off"'])
        @include('layoutmodule::partials.field', ['name' => 'birth_date', 'label' => 'Birth Date', 'type' => 'date', 'value' => $staff?->birth_date?->format('Y-m-d')])
        @include('layoutmodule::partials.field', ['name' => 'nationality', 'label' => 'Nationality', 'value' => $f('nationality')])
        @include('layoutmodule::partials.field', ['name' => 'marital_status', 'label' => 'Marital Status', 'type' => 'select',
            'options' => ['' => '-'] + config('hrmodule.marital_statuses'), 'value' => $f('marital_status')])
        <div data-show-if="gender=male">
            @include('layoutmodule::partials.field', ['name' => 'military_status', 'label' => 'Military Status', 'type' => 'select',
                'options' => ['' => '-'] + config('hrmodule.military_statuses'), 'value' => $f('military_status')])
        </div>
        @include('layoutmodule::partials.field', ['name' => 'educational_qualification', 'label' => 'Education', 'value' => $f('educational_qualification')])
        @include('layoutmodule::partials.field', ['name' => 'address', 'label' => 'Address', 'value' => $f('address'), 'wrapperClass' => 'form-grid__full'])
    </div>

    <div class="form-section mt-8">Salary Payment</div>
    <div class="form-grid">
        @include('layoutmodule::partials.field', ['name' => 'pay_method', 'label' => 'Paid By', 'type' => 'select', 'required' => true,
            'options' => config('hrmodule.pay_methods'), 'value' => $f('pay_method', 'cash')])
        <div data-show-if="pay_method=wallet">
            @include('layoutmodule::partials.field', ['name' => 'wallet_number', 'label' => 'Wallet Number', 'type' => 'tel', 'value' => $f('wallet_number'), 'attrs' => 'inputmode="tel" autocomplete="off"'])
        </div>
        <div data-show-if="pay_method=bank">
            @include('layoutmodule::partials.field', ['name' => 'bank_name', 'label' => 'Bank', 'value' => $f('bank_name')])
        </div>
        <div data-show-if="pay_method=bank">
            @include('layoutmodule::partials.field', ['name' => 'bank_account', 'label' => 'Account No. / IBAN', 'value' => $f('bank_account'), 'attrs' => 'autocomplete="off"'])
        </div>
    </div>

    <div class="form-section mt-8">Emergency Contact</div>
    <div class="form-grid">
        @include('layoutmodule::partials.field', ['name' => 'emergency_name', 'label' => 'Name', 'value' => $f('emergency_name')])
        @include('layoutmodule::partials.field', ['name' => 'emergency_relation', 'label' => 'Relation', 'value' => $f('emergency_relation'), 'attrs' => 'placeholder="Father, wife …"'])
        @include('layoutmodule::partials.field', ['name' => 'emergency_phone', 'label' => 'Phone', 'type' => 'tel', 'value' => $f('emergency_phone'), 'attrs' => 'inputmode="tel"'])
    </div>
@endif

</div>

@include('hrmodule::Staff.partials.access-fields')

{{-- a new file: its documents can be uploaded at once (documents.manage; the same rules as the Documents tab) --}}
@if (!$staff && Gate::allows('documents.manage'))
    @include('hrmodule::Staff.partials.documents-fields')
@endif

<div class="mt-8">
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $f('notes')])
</div>
