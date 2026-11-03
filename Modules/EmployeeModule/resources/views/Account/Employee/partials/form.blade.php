{{-- Employee create / edit fields. $employee is null on create. $positions / $shifts / $roles = [id => name]; $defaultRoleId --}}
<div class="form-section">Employee Data</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $employee?->name, 'attrs' => 'autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true,
        'options' => \Modules\EmployeeModule\app\Models\Employee::GENDERS, 'value' => $employee?->gender ?? 'male'])
    @include('layoutmodule::partials.field', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'value' => $employee?->phone,
        'attrs' => 'inputmode="tel" pattern="\+?[0-9 ]{8,30}" placeholder="01012345678"', 'hint' => 'Spaces are removed automatically.'])
    @include('layoutmodule::partials.field', ['name' => 'another_phone', 'label' => 'Another Phone', 'type' => 'tel', 'value' => $employee?->another_phone,
        'attrs' => 'inputmode="tel" pattern="\+?[0-9 ]{8,30}"'])
    @include('layoutmodule::partials.field', ['name' => 'birth_date', 'label' => 'Birth Date', 'type' => 'date', 'value' => $employee?->birth_date?->format('Y-m-d'),
        'attrs' => 'max="' . now()->subDay()->format('Y-m-d') . '"'])
    @include('layoutmodule::partials.field', ['name' => 'educational_qualification', 'label' => 'Educational Qualification', 'value' => $employee?->educational_qualification])
    @include('layoutmodule::partials.field', ['name' => 'address', 'label' => 'Address', 'value' => $employee?->address])
    <div class="flex items-center gap-4">
        @if ($employee)
            @include('employeemodule::Account.Employee.partials.photo')
        @endif
        <div class="flex-1">
            @include('layoutmodule::partials.field', ['name' => 'image', 'label' => 'Photo', 'type' => 'file', 'hint' => 'Image, max 2 MB.'])
        </div>
    </div>
</div>

<div class="form-section mt-8">Work</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'position_id', 'label' => 'Position', 'type' => 'select',
        'options' => ['' => '- None -'] + $positions, 'value' => $employee?->position_id ?: null,
        'hint' => empty($positions) ? 'No positions yet, add them from Employees > Positions.' : null])
    @include('layoutmodule::partials.field', ['name' => 'shift_id', 'label' => 'Shift', 'type' => 'select',
        'options' => ['' => '- None -'] + $shifts, 'value' => $employee?->shift_id ?: null,
        'hint' => empty($shifts) ? 'No shifts yet, add them from Employees > Shifts.' : null])
    {{-- docs/roles-plan.md: what he may do (Settings › Roles); a missing / deleted role = the default one --}}
    @include('layoutmodule::partials.field', ['name' => 'role_id', 'label' => 'Role', 'type' => 'select', 'required' => true,
        'options' => $roles, 'value' => $employee && isset($roles[$employee->role_id]) ? $employee->role_id : $defaultRoleId,
        'hint' => 'What he can do in the system (Settings › Roles).'])
    @include('layoutmodule::partials.field', ['name' => 'salary', 'label' => 'Salary', 'type' => 'number', 'value' => $employee?->salary,
        'attrs' => 'min="0" step="0.01" max="99999999"'])
    @include('layoutmodule::partials.field', ['name' => 'join_date', 'label' => 'Join Date', 'type' => 'date', 'value' => $employee?->join_date?->format('Y-m-d')])
    @include('layoutmodule::partials.field', ['name' => 'leave_date', 'label' => 'Leave Date', 'type' => 'date', 'value' => $employee?->leave_date?->format('Y-m-d'),
        'hint' => 'From this date the employee can not log in.'])
</div>

<div class="form-section mt-8">Login Details</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'value' => $employee?->user?->email,
        'attrs' => 'autocomplete="off"', 'hint' => 'The employee logs in with this email, he can not change it.'])
    <div></div>
    @include('layoutmodule::partials.field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => !$employee,
        'hint' => $employee ? 'Leave empty to keep the current password.' : 'At least 6 characters, the employee can change it later.', 'attrs' => 'autocomplete=new-password'])
    @include('layoutmodule::partials.field', ['name' => 'password_confirmation', 'label' => 'Confirm Password', 'type' => 'password', 'required' => !$employee, 'attrs' => 'autocomplete=new-password'])
</div>

<div class="form-section mt-8">Attachments</div>
@include('employeemodule::Account.Employee.partials.attachments-input')
