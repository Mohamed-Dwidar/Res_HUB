{{-- a staff file's status --}}
@php($class = ['active' => 'badge-active', 'on_leave' => 'badge-status-info', 'resigned' => 'badge-inactive', 'terminated' => 'badge-status-danger'][$staff->status] ?? 'badge-inactive')
<span class="badge {{ $class }}" @if ($staff->status_date) title="Since {{ $staff->status_date->format('d-m-Y') }}" @endif>{{ $staff->status_label }}</span>
