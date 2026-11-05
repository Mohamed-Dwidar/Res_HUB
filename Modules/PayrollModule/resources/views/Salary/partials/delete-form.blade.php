<form method="POST" action="{{ route($area . '.payroll.salaries.destroy', [$staff->id, $salary->id]) }}" data-ajax
    data-confirm="The salary from {{ $salary->effective_from->format('d-m-Y') }} ({{ number_format($salary->total, 2) }}) will be deleted. Use it only for a row typed by mistake — a raise is a new salary.{{ $gap ? ' WARNING: ' . $gap : '' }}"
    data-confirm-title="Delete this salary?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }} text-danger">
        <i data-lucide="trash-2" class="{{ config('layoutmodule.menu.icon') }}"></i> Delete
    </button>
</form>
