<form method="POST" action="{{ route('account.hr.adjustment-types.destroy', $adjustmentType->id) }}" data-ajax
    data-confirm="&quot;{{ $adjustmentType->name }}&quot; will be deleted."
    data-confirm-title="Delete it?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $adjustmentType->adjustments_count ? 'Used by bonuses / deductions: switch it off instead' : 'Delete' }}" @disabled($adjustmentType->adjustments_count)>
        <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
    </button>
</form>
