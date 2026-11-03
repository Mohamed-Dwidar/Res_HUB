@php($blocked = $role->is_default ? 'The default role can\'t be deleted' : ($role->employees_count ? 'Given to employees' : null))
<form method="POST" action="{{ route('account.settings.roles.destroy', $role->id) }}" class="{{ !empty($menu) ? '' : 'inline-form' }}" data-ajax data-row="{{ $role->id }}"
    data-confirm="&quot;{{ $role->name }}&quot; will be deleted from your roles."
    data-confirm-title="Delete this role?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    @if (!empty($menu))
        <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $blocked ?? 'Delete' }}" @disabled($blocked)>
            <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
        </button>
    @else
        <button type="submit" class="btn btn-danger" title="{{ $blocked ?? 'Delete' }}" @disabled($blocked)><i data-lucide="trash-2"></i> Delete</button>
    @endif
</form>
