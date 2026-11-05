{{-- the owner deletes a file made by mistake (refused while it has documents) --}}
<form method="POST" action="{{ route('account.hr.staff.destroy', $staff->id) }}" class="{{ !empty($menu) ? '' : 'inline-form' }}" data-ajax data-row="{{ $staff->id }}"
    data-confirm="The staff file of &quot;{{ $staff->name }}&quot; will be deleted. To keep its history, change its status instead."
    data-confirm-title="Delete this staff file?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    @if (!empty($menu))
        <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger">
            <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
        </button>
    @else
        <button type="submit" class="btn btn-danger"><i data-lucide="trash-2"></i> Delete</button>
    @endif
</form>
