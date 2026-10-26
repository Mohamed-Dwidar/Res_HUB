<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\ShiftCloseRequest;
use Modules\PaymentModule\app\Http\Requests\ShiftReopenRequest;
use Modules\PaymentModule\app\Services\ShiftClosingService;
use Modules\UserModule\app\Models\User;

/**
 * The account and its employees' shifts (docs/payments-plan.md §2b), from the Daily Summary: view any closing (its payments,
 * totals and history: the close + every payment changed after it), close an employee's open shift on their behalf
 * (confirmation popup, logged), reopen a closing (reason, logged; 2026-10-26). ?date= / date: the Daily Summary day it was opened from, the answer goes back to it.
 * The account's OWN shift: My Shift (MyShiftAccountModuleController).
 */
class ShiftClosingAccountModuleController extends Controller
{
    private ShiftClosingService $shiftService;

    public function __construct(ShiftClosingService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // back to the Daily Summary day it came from, else the closing
    private function back(Request $request, $closingId): string
    {
        return $request->filled('date')
            ? route('account.daily-summary.index', ['date' => $request->input('date')])
            : route('account.shifts.show', $closingId);
    }

    public function show($id)
    {
        $closing = $this->shiftService->findOne($this->accountId(), $id);
        $closing->setRelation('payments', $this->shiftService->popupPayments($closing));
        $closing->setRelation('expenses', $this->shiftService->popupExpenses($closing));

        return view('paymentmodule::Shift.show', compact('closing'));
    }

    // close on behalf: the employee's open shift + "Confirm closing" (?user_id=&date=)
    public function confirm(Request $request)
    {
        $employee = $this->employee($request->input('user_id'));

        return view('paymentmodule::Shift.confirm', [
            'period' => $this->shiftService->openPeriod($this->accountId(), $employee->id),
            'employee' => $employee,
            'logout' => false,
        ]);
    }

    public function close(ShiftCloseRequest $request)
    {
        $employee = $this->employee($request->validated('user_id'));
        $closing = $this->shiftService->close($this->accountId(), $employee, (int) $request->validated('up_to_id'), Auth::user(), 'close',
            (int) $request->validated('up_to_expense_id'));

        return response()->json([
            'message' => 'The shift of ' . $employee->userable->displayName() . ' has been closed: ' . number_format($closing->net_total, 2) . ' to hand over.',
            'redirect' => $this->back($request, $closing->id),
        ]);
    }

    // reopen (popup: the totals + a reason); only the login's last closing, not an imported one (ShiftClosingService::reopenError())
    public function reopenForm($id)
    {
        $closing = $this->shiftService->findOne($this->accountId(), $id);

        return view('paymentmodule::Shift.reopen', ['closing' => $closing, 'error' => $this->shiftService->reopenError($closing)]);
    }

    public function reopen(ShiftReopenRequest $request, $id)
    {
        $closing = $this->shiftService->reopen($this->accountId(), $id, Auth::user(), $request->validated('reason'));
        $name = $closing->user?->userable?->displayName() ?? 'this login';
        $own = (int) $closing->user_id === (int) Auth::id();

        // back to the Daily Summary day (?date=), the account's own My Shift, else the Daily Summary of the closing's day
        return response()->json([
            'message' => 'The shift has been reopened: its cash in / out are back in the open shift of ' . $name . '.',
            'reload' => true,
            'redirect' => $request->filled('date')
                ? route('account.daily-summary.index', ['date' => $request->input('date')])
                : ($own ? route('account.shift.index') : route('account.daily-summary.index', ['date' => $closing->closed_at?->toDateString()])),
        ]);
    }

    // a login of one of the account's employees (404 otherwise; the service checks it again)
    private function employee($userId): User
    {
        $user = User::with('userable')->whereIn('id', $this->shiftService->employeeUserIds($this->accountId()))->find((int) $userId);
        abort_unless($user, 404);

        return $user;
    }
}
