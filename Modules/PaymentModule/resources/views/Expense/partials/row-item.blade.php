{{-- the expenses list Expense cell: the item (opens the expense), the expense no. (small), a paperclip when there is a receipt --}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<a href="{{ route($area . '.expenses.show', $expense->id) }}" data-modal class="font-medium">{{ $expense->item }}</a>
<div class="text-xs text-slate-500">
    #{{ $expense->expense_no }}
    @if ($expense->is_company) <span class="badge badge-status-slate" title="Paid by the company, not in any shift">Company</span> @endif
    @if ($expense->attachment)
        <i data-lucide="paperclip" class="inline h-3 w-3" title="Receipt attached"></i>
    @endif
</div>
