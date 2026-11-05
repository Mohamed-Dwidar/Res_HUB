{{-- Staff list: position + department --}}
<div>{{ $staff->position?->name ?? '-' }}</div>
@if ($staff->department)
    <div class="text-xs text-slate-500">{{ $staff->department->name }}</div>
@endif
