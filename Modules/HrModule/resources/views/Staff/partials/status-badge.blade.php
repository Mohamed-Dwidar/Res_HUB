{{-- a staff file's status + "On leave" while today is inside one of their leaves (computed, LeaveService::currentLeave(); the
     status itself is not changed — not repeated when the status is already On leave) --}}
@php($class = ['active' => 'badge-active', 'on_leave' => 'badge-status-info', 'resigned' => 'badge-inactive', 'terminated' => 'badge-status-danger'][$staff->status] ?? 'badge-inactive')
<span class="badge {{ $class }}" @if ($staff->status_date) title="Since {{ $staff->status_date->format('d-m-Y') }}" @endif>{{ $staff->status_label }}</span>
@if ($staff->status !== 'on_leave' && ($onLeave = \Modules\HrModule\app\Services\LeaveService::currentLeave($staff)))
    <span class="badge badge-status-info" title="{{ $onLeave->type?->name }} · {{ $onLeave->datesText() }}">On leave</span>
@endif
