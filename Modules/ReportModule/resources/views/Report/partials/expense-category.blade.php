{{-- the Details "Category" cell: the category + a Company badge for a company Cash Out --}}
{{ $expense->expenseCategory?->name ?? '-' }}
@if ($expense->is_company)
    <span class="badge badge-status-info">Company</span>
@endif
