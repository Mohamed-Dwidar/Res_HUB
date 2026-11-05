<form method="POST" action="{{ route('account.hr.departments.destroy', $department->id) }}" data-ajax
    data-confirm="&quot;{{ $department->name }}&quot; will be deleted."
    data-confirm-title="Delete it?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $department->positions_count ? 'Used by job titles: switch it off instead' : 'Delete' }}" @disabled($department->positions_count)>
        <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
    </button>
</form>
