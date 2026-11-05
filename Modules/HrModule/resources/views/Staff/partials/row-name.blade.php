{{-- Staff list: the name (opens the staff page), the Arabic name, a login icon when linked --}}
<a href="{{ route($area . '.hr.staff.show', $staff->id) }}" class="font-medium">{{ $staff->name ?: '-' }}</a>
@if ($staff->user)
    <i data-lucide="key-round" class="staff-login-icon" title="Has a system login"></i>
@endif
@if ($staff->is_external)
    <span class="badge badge-status-slate">External</span>
@endif
@if ($staff->name_ar && $staff->name_ar !== $staff->name)
    <div class="text-xs text-slate-500" dir="rtl">{{ $staff->name_ar }}</div>
@endif
