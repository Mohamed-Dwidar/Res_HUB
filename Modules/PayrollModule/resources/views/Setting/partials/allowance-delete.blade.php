<form method="POST" action="{{ route('account.hr.allowance-types.destroy', $allowanceType->id) }}" data-ajax
    data-confirm="&quot;{{ $allowanceType->name }}&quot; will be deleted."
    data-confirm-title="Delete it?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $allowanceType->items_count ? 'Used by salaries: switch it off instead' : 'Delete' }}" @disabled($allowanceType->items_count)>
        <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
    </button>
</form>
