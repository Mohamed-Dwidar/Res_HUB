<form method="POST" action="{{ route('account.hr.document-types.destroy', $documentType->id) }}" data-ajax
    data-confirm="&quot;{{ $documentType->name }}&quot; will be deleted."
    data-confirm-title="Delete it?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger" title="{{ $documentType->documents_count ? 'Used by documents: switch it off instead' : 'Delete' }}" @disabled($documentType->documents_count)>
        <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
    </button>
</form>
