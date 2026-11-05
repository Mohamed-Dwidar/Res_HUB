<form method="POST" action="{{ route('account.employee-positions.destroy', $position->id) }}" class="{{ !empty($menu) ? '' : 'inline-form' }}" data-ajax data-row="{{ $position->id }}"
    data-confirm="&quot;{{ $position->name }}&quot; will be deleted from your positions list."
    data-confirm-title="Delete this position?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    @if (!empty($menu))
        <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $position->employees_count ? 'Used by users' : 'Delete' }}" @disabled($position->employees_count)>
            <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
        </button>
    @else
        <button type="submit" class="btn btn-danger" title="{{ $position->employees_count ? 'Used by users' : 'Delete' }}" @disabled($position->employees_count)><i data-lucide="trash-2"></i> Delete</button>
    @endif
</form>
