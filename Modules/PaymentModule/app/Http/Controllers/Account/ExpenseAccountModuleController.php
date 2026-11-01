<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Controllers\Concerns\ExpenseActions;

// logged in account: all the account's expenses (ExpenseActions: also the edit) + the owner's delete (a closed shift: reason, logged on it)
class ExpenseAccountModuleController extends Controller
{
    use ExpenseActions;

    protected function area(): string
    {
        return 'account';
    }

    // the delete popup: the reason is required
    public function deleteForm($id)
    {
        $expense = $this->expenseService->findOne($this->accountId(), $id);
        return view('paymentmodule::Expense.delete', ['expense' => $expense, 'closedShift' => $this->expenseService->closedShiftOf($expense)]);
    }

    public function destroy(Request $request, $id)
    {
        $request->validate(['delete_reason' => 'required|string|max:255'], [], ['delete_reason' => 'reason']);
        $expense = $this->expenseService->delete($this->accountId(), $id, $request->input('delete_reason'), Auth::user());

        return response()->json([
            'message' => 'Cash Out #' . $expense->expense_no . ' has been deleted.',
            'row' => null,
            'reload' => true,
            'redirect' => route('account.expenses.index'),
        ]);
    }
}
