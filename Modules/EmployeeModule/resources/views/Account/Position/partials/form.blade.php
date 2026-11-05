{{-- Job title create / edit fields. $position is null on create; $departments = [id => name] (HR › Employee Departments), the field only when there are some --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $position?->name, 'attrs' => 'autofocus'])
    @if ($departments)
        @include('layoutmodule::partials.field', ['name' => 'department_id', 'label' => 'Department', 'type' => 'select',
            'options' => ['' => '- None -'] + $departments, 'value' => $position?->department_id ?: null,
            'hint' => 'Optional. People with this title belong to this department.'])
    @endif
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $position?->is_active ?? true, 'hint' => 'Inactive positions are hidden from the user and staff forms.'])
</div>
