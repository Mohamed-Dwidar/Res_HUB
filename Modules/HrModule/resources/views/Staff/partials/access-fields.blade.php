{{--
    The staff form's System Access section (user 2026-11-04): the login of this person. $access = StaffService::accessInfo()
    (login, active, roleName, lastLogin); $canAccess = users.manage and not one's own file — else read only.
    ON: Login Email + Role + password (new login: required; existing: only with "Change password" — the password is never shown).
    OFF on an existing login = it can't log in any more (kept with its Access Log history).
--}}
@php($login = $access['login'])
<div class="form-section mt-8">System Access</div>
@if ($canAccess)
    @include('layoutmodule::partials.checkbox', ['name' => 'has_access', 'label' => 'Can log in to the system', 'checked' => $access['active'],
        'hint' => $login ? ($access['active'] ? 'Untick to block the login (it is kept with its history).' : 'The login is off since ' . $login->leave_date?->format('d-m-Y') . '. Tick to let them log in again.')
            : 'Gives this person a login with the email, password and role below.'])
    <div data-show-if="has_access" class="mt-5 form-grid">
        @include('layoutmodule::partials.field', ['name' => 'login_email', 'label' => 'Login Email', 'type' => 'email', 'required' => true,
            'value' => $access['email'] ?? $staff?->email, 'attrs' => 'autocomplete="off"', 'hint' => 'Used to log in; the user can\'t change it.'])
        @include('layoutmodule::partials.field', ['name' => 'role_id', 'label' => 'Role', 'type' => 'select', 'required' => true,
            'options' => $roles, 'value' => $login && isset($roles[$login->role_id]) ? $login->role_id : $defaultRoleId, 'hint' => 'What they can do (Settings › Roles).'])
        @if ($login)
            <div class="form-grid__full">
                @include('layoutmodule::partials.checkbox', ['name' => 'change_password', 'label' => 'Change password', 'checked' => false])
            </div>
        @endif
        <div @if ($login) data-show-if="change_password" @endif class="form-grid__full form-grid">
            @include('layoutmodule::partials.field', ['name' => 'password', 'label' => $login ? 'New Password' : 'Password', 'type' => 'password', 'required' => true,
                'attrs' => 'autocomplete=new-password', 'hint' => 'At least 6 characters; the user can change it later.'])
            @include('layoutmodule::partials.field', ['name' => 'password_confirmation', 'label' => 'Confirm Password', 'type' => 'password', 'required' => true,
                'attrs' => 'autocomplete=new-password'])
        </div>
    </div>
@else
    @include('hrmodule::Staff.partials.access-info')
    <div class="{{ config('layoutmodule.form.hint') }}">
        {{ Gate::allows('users.manage') ? 'You can\'t change your own system access.' : 'Only a user with "Manage system access" can change it.' }}
    </div>
@endif
