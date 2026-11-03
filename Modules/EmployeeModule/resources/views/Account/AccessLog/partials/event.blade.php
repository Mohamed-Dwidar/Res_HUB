{{-- an Access Log event badge --}}
@php($badge = ['login' => 'badge-status-success', 'login_failed' => 'badge-status-danger', 'logout' => 'badge-status-slate',
    'timeout' => 'badge-status-warning', 'export' => 'badge-status-info', 'action' => 'badge-status-primary'][$log->event] ?? 'badge-status-slate')
<span class="badge {{ $badge }}">{{ $log->event_label }}</span>
