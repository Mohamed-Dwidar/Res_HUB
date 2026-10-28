<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Controllers\Concerns\PaymentActions;
use Modules\PaymentModule\app\Http\Requests\PaymentDeleteRequest;

// logged in account: the account's payments (add / view / refund / edit in PaymentActions) + the owner's delete
// (a payment of a closed shift: reason required, logged on the shift; PaymentService checks the owner again: the routes are account only)
class PaymentAccountModuleController extends Controller
{
    use PaymentActions;

    protected function area(): string
    {
        return 'account';
    }

    // a reservation / package payment: back to its view after the change (popup or full page)
    private function payableUrl($payment): ?string
    {
        return $payment->payable_kind ? route('account.' . $payment->payable_kind . 's.show', $payment->payable_id) : null;
    }

    // the delete popup: the reason is required
    public function deleteForm($id)
    {
        $payment = $this->paymentService->findOne($this->accountId(), $id);
        return view('paymentmodule::Payment.delete', ['payment' => $payment, 'closedShift' => $this->paymentService->closedShiftOf($payment),
            'payableName' => $this->paymentService->payableName($payment->payable, false)]);
    }

    public function destroy(PaymentDeleteRequest $request, $id)
    {
        $payment = $this->paymentService->delete($this->accountId(), $id, $request->validated('delete_reason'), Auth::user());
        $payableUrl = $this->payableUrl($payment);

        return response()->json([
            'message' => ($payment->type === 'refund' ? 'Refund #' : 'Cash In #') . $payment->payment_no . ' has been deleted.',
            'row' => null,
            'reload' => true,
            'modal' => $payableUrl,
            'redirect' => $payableUrl ?? route('account.payments.index'),
        ]);
    }
}
