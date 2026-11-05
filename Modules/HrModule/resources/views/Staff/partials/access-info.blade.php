{{-- System Access, read only (the profile, and the form without users.manage): $access = StaffService::accessInfo() --}}
@if ($access['login'])
    <dl class="detail-list">
        <div><dt>Login Email</dt><dd>{{ $access['email'] ?? '-' }}</dd></div>
        <div><dt>Role</dt><dd>{{ $access['roleName'] ?? '-' }}</dd></div>
        <div><dt>Last Login</dt><dd>{{ $access['lastLogin']?->created_at?->format('d-m-Y h:i A') ?? 'Never' }}</dd></div>
        <div><dt>Access</dt><dd>
            @if ($access['active'])
                <span class="badge badge-active">Active</span>
            @else
                <span class="badge badge-inactive">Inactive</span>
                @if ($access['login']->leave_date) <span class="text-slate-500">since {{ $access['login']->leave_date->format('d-m-Y') }}</span> @endif
            @endif
        </dd></div>
    </dl>
@else
    <div class="text-slate-500">No system access (can't log in).</div>
@endif
