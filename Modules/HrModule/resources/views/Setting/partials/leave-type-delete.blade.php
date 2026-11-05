<form method="POST" action="{{ route('account.hr.leave-types.destroy', $leaveType->id) }}" data-ajax
    data-confirm="&quot;{{ $leaveType->name }}&quot; will be deleted."
    data-confirm-title="Delete it?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $leaveType->leaves_count ? 'Used by leaves: switch it off instead' : 'Delete' }}" @disabled($leaveType->leaves_count)>
        <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
    </button>
</form>
