{{-- The Profile tab: work, contact, (hr.sensitive) identity / salary payment / emergency, notes, history --}}
@php($dash = fn ($value) => filled($value) ? $value : '-')
<div class="form-section">Work</div>
<dl class="detail-list">
    <div><dt>Job Title</dt><dd>{{ $staff->position?->name ?? '-' }}</dd></div>
    <div><dt>Department</dt><dd>{{ $staff->department?->name ?? '-' }}</dd></div>
    <div><dt>Usual Shift</dt><dd>{{ $staff->shift ? $staff->shift->name . ' (' . $staff->shift->time_range . ')' : '-' }}</dd></div>
    <div><dt>Hire Date</dt><dd>{{ $staff->join_date?->format('d-m-Y') ?? '-' }}</dd></div>
    @if ($staff->probation_ends_on)
        <div><dt>Probation Ends</dt><dd>{{ $staff->probation_ends_on->format('d-m-Y') }}</dd></div>
    @endif
    <div><dt>Status</dt><dd>{{ $staff->status_label }}@if ($staff->status_date) · since {{ $staff->status_date->format('d-m-Y') }}@endif @if ($staff->status_reason)<div class="text-xs text-slate-500">{{ $staff->status_reason }}</div>@endif</dd></div>
</dl>

<div class="form-section mt-8">System Access</div>
@include('hrmodule::Staff.partials.access-info')

<div class="form-section mt-8">Contact</div>
<dl class="detail-list">
    <div><dt>Gender</dt><dd>{{ \Modules\EmployeeModule\app\Models\Employee::GENDERS[$staff->gender] ?? '-' }}</dd></div>
    <div><dt>Mobile</dt><dd>{{ $dash($staff->phone) }}</dd></div>
    @if ($staff->another_phone)
        <div><dt>Another Phone</dt><dd>{{ $staff->another_phone }}</dd></div>
    @endif
    <div><dt>Email</dt><dd>{{ $dash($staff->email) }}</dd></div>
</dl>

@can('hr.sensitive')
    <div class="form-section mt-8">Identity</div>
    <dl class="detail-list">
        <div><dt>{{ \Modules\HrModule\app\Models\Staff::label('id_types', $staff->id_type) }}</dt><dd>{{ $dash($staff->nationalIdFor(Gate::allows('hr.manage'))) }}</dd></div>
        <div><dt>Birth Date</dt><dd>{{ $staff->birth_date ? $staff->birth_date->format('d-m-Y') . ' (' . $staff->birth_date->age . ' years)' : '-' }}</dd></div>
        <div><dt>Nationality</dt><dd>{{ $dash($staff->nationality) }}</dd></div>
        <div><dt>Marital Status</dt><dd>{{ \Modules\HrModule\app\Models\Staff::label('marital_statuses', $staff->marital_status) }}</dd></div>
        @if ($staff->gender === 'male')
            <div><dt>Military Status</dt><dd>{{ \Modules\HrModule\app\Models\Staff::label('military_statuses', $staff->military_status) }}</dd></div>
        @endif
        <div><dt>Education</dt><dd>{{ $dash($staff->educational_qualification) }}</dd></div>
        <div class="wide"><dt>Address</dt><dd>{{ $dash($staff->address) }}</dd></div>
    </dl>

    <div class="form-section mt-8">Salary Payment</div>
    <dl class="detail-list">
        <div><dt>Paid By</dt><dd>{{ \Modules\HrModule\app\Models\Staff::label('pay_methods', $staff->pay_method) }}</dd></div>
        @if ($staff->pay_method === 'bank')
            <div><dt>Bank</dt><dd>{{ $dash($staff->bank_name) }}</dd></div>
            <div><dt>Account No. / IBAN</dt><dd>{{ $dash($staff->bank_account) }}</dd></div>
        @elseif ($staff->pay_method === 'wallet')
            <div><dt>Wallet Number</dt><dd>{{ $dash($staff->wallet_number) }}</dd></div>
        @endif
    </dl>

    <div class="form-section mt-8">Emergency Contact</div>
    <dl class="detail-list">
        <div><dt>Name</dt><dd>{{ $dash($staff->emergency_name) }}@if ($staff->emergency_relation) ({{ $staff->emergency_relation }})@endif</dd></div>
        <div><dt>Phone</dt><dd>{{ $dash($staff->emergency_phone) }}</dd></div>
    </dl>
@endcan

@if ($staff->notes)
    <div class="form-section mt-8">Notes</div>
    <div class="whitespace-pre-line">{{ $staff->notes }}</div>
@endif

@include('hrmodule::Staff.partials.history')
