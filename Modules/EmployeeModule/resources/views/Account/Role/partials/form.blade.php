{{--
    Role add / edit fields (docs/roles-plan.md). $values = [name, permissions (keys), idle_hours]; $catalog = [group => [key => label]];
    $requires = [key => [keys it needs]] (shown as "Also gives …"; RoleService adds them on save).
--}}
@php($labels = array_merge(...array_values($catalog)))
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $values['name'], 'attrs' => 'autofocus maxlength="100"'])
    @include('layoutmodule::partials.field', ['name' => 'idle_hours', 'label' => 'Idle Timeout (hours)', 'type' => 'number', 'value' => $values['idle_hours'],
        'attrs' => 'min="1" max="24" step="1"', 'hint' => 'Logged out after this many hours without using the system (1–24). Empty = no limit.'])
</div>
@if ($role?->is_default)
    <div class="role-default-note">The default role: new users get it, and so does a user without a role. It can't be deleted.</div>
@endif

@foreach ($catalog as $group => $permissions)
    <div class="form-section mt-6">{{ $group }}</div>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach ($permissions as $key => $label)
            @include('layoutmodule::partials.checkbox', ['name' => 'permissions[' . $key . ']', 'label' => $label,
                'checked' => in_array($key, $values['permissions'], true),
                'hint' => !empty($requires[$key]) ? 'Also gives: ' . implode(', ', array_map(fn ($needed) => $labels[$needed] ?? $needed, $requires[$key])) : null])
        @endforeach
    </div>
@endforeach
@error('permissions')
    <div class="{{ config('layoutmodule.form.error') }}">{{ $message }}</div>
@enderror
