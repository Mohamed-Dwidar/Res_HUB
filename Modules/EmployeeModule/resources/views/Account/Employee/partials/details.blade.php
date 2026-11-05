{{-- Employee data (account view + the employee's own "My Profile"). --}}
<div class="form-section mt-6">Personal Data</div>
<dl class="detail-list">
    <div><dt>Phone</dt><dd>{{ $employee->phone ?: '-' }}</dd></div>
    <div><dt>Another Phone</dt><dd>{{ $employee->another_phone ?: '-' }}</dd></div>
    <div><dt>Gender</dt><dd>{{ $employee->gender_label }}</dd></div>
    <div><dt>Birth Date</dt><dd>{{ $employee->birth_date?->format('Y-m-d') ?? '-' }}</dd></div>
    <div><dt>Educational Qualification</dt><dd>{{ $employee->educational_qualification ?: '-' }}</dd></div>
    <div><dt>Address</dt><dd>{{ $employee->address ?: '-' }}</dd></div>
</dl>

<div class="form-section mt-8">Work</div>
<dl class="detail-list">
    <div><dt>Position</dt><dd>{{ $employee->position?->name ?? '-' }}</dd></div>
    {{-- the account's view only (docs/roles-plan.md); his own profile unchanged --}}
    @if (!empty($role))
        <div><dt>Role</dt><dd><a href="{{ route('account.settings.roles.edit', $role->id) }}" data-modal>{{ $role->name }}</a></dd></div>
    @endif
    <div><dt>Shift</dt><dd>{{ $employee->shift ? $employee->shift->name . ' (' . $employee->shift->time_range . ')' : '-' }}</dd></div>
    <div><dt>Join Date</dt><dd>{{ $employee->join_date?->format('Y-m-d') ?? '-' }}</dd></div>
    <div><dt>Inactive From</dt><dd>{{ $employee->leave_date?->format('Y-m-d') ?? '-' }}</dd></div>
    @if (!empty($withSalary))
        <div><dt>Salary</dt><dd>{{ $employee->salary !== null ? number_format($employee->salary, 2) : '-' }}</dd></div>
    @endif
</dl>
