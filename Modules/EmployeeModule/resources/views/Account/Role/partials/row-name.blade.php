<a href="{{ route('account.settings.roles.edit', $role->id) }}" data-modal class="font-medium">{{ $role->name }}</a>
@if ($role->is_default)
    <span class="badge badge-status-primary">Default</span>
@endif
