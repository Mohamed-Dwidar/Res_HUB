{{-- Shift create / edit fields. $shift is null on create. --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $shift?->name, 'attrs' => 'autofocus', 'hint' => 'e.g. Morning, Evening, Night.'])
    <div></div>
    @include('layoutmodule::partials.field', ['name' => 'start_time', 'label' => 'Start Time', 'type' => 'time', 'required' => true, 'value' => $shift?->start])
    @include('layoutmodule::partials.field', ['name' => 'end_time', 'label' => 'End Time', 'type' => 'time', 'required' => true, 'value' => $shift?->end,
        'hint' => 'Can be before the start time for a night shift.'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $shift?->is_active ?? true, 'hint' => 'Inactive shifts are hidden from the user and staff forms.'])
</div>
