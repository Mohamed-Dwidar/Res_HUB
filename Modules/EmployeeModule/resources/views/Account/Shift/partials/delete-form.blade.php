<form method="POST" action="{{ route('account.employee-shifts.destroy', $shift->id) }}" class="{{ !empty($menu) ? '' : 'inline-form' }}" data-ajax data-row="{{ $shift->id }}"
    data-confirm="&quot;{{ $shift->name }}&quot; will be deleted from your shifts list."
    data-confirm-title="Delete this shift?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    @if (!empty($menu))
        <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $shift->employees_count ? 'Used by users' : 'Delete' }}" @disabled($shift->employees_count)>
            <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
        </button>
    @else
        <button type="submit" class="btn btn-danger" title="{{ $shift->employees_count ? 'Used by users' : 'Delete' }}" @disabled($shift->employees_count)><i data-lucide="trash-2"></i> Delete</button>
    @endif
</form>
