{{--
    The expenses of a closed shift (the shift popup), newest first, at most ShiftClosingService::POPUP_LIMIT: the same columns and
    cells as the Expenses list (Expense/partials/row-*): Expense | Category | Date (time) | Method | Amount | row menu.
--}}

<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr><th>Cash Out</th><th>Category</th><th>Date</th><th>Method</th><th>Amount</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($expenses as $expense)
                <tr id="row-{{ $expense->id }}">
                    <td>@include('paymentmodule::Expense.partials.row-item')</td>
                    <td>{{ $expense->expenseCategory?->name ?? '-' }}</td>
                    <td class="whitespace-nowrap">@include('paymentmodule::Payment.partials.record-date', ['record' => $expense])</td>
                    <td>{{ $expense->method_name }}</td>
                    <td class="whitespace-nowrap">@include('paymentmodule::Expense.partials.row-amount')</td>
                    <td>@include('paymentmodule::Expense.partials.row-actions')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
