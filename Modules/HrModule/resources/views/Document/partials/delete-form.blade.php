<form method="POST" action="{{ route($area . '.hr.staff.documents.destroy', [$staff->id, $document->id]) }}" class="{{ !empty($menu) ? '' : 'inline-form' }}" data-ajax
    data-confirm="&quot;{{ $document->label }}&quot; and its {{ $document->files->count() }} file(s) will be deleted."
    data-confirm-title="Delete this document?"
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
