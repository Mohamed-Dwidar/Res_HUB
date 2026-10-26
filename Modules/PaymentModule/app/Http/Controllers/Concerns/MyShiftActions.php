<?php

namespace Modules\PaymentModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\ShiftCloseRequest;
use Modules\PaymentModule\app\Services\ShiftClosingService;
use Yajra\DataTables\Facades\DataTables;

/**
 * "My Shift" of the logged in login, shared by the employee and the account (docs/payments-plan.md §2b: every login collects
 * into its own shift, the ONE closing level): ONLY their own payments not closed yet (the open shift), totals by method
 * (refunds their own minus line, withholding apart), "Close My Shift" → confirm() popup → close(); their past closings below.
 * The controller gives area(): "account" | "employee" (route names {area}.shift.*; a closing opens at showRoute()).
 */
trait MyShiftActions
{
    private ShiftClosingService $shiftService;

    abstract protected function area(): string;

    public function __construct(ShiftClosingService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // one of the closings (employee: their own, employee.shift.show; account: any, account.shifts.show)
    private function showRoute($closingId): string
    {
        return route($this->area() === 'account' ? 'account.shifts.show' : 'employee.shift.show', $closingId);
    }

    // the open shift + the closings list
    public function index()
    {
        return view('paymentmodule::Shift.index', ['period' => $this->shiftService->openPeriod($this->accountId(), Auth::id())]);
    }

    // DataTables server side: the login's own closings (an employee: without the imported one — the account sees it, badged)
    public function data(Request $request)
    {
        // + the dates of its payments (paid_at first / last) for the Payments column
        $query = $this->shiftService->listQuery($this->accountId(), Auth::id())
            ->when($this->area() === 'employee', fn ($query) => $query->notImported())
            ->withMin('payments', 'paid_at')->withMax('payments', 'paid_at');

        return DataTables::eloquent($query)
            ->setRowId(fn ($closing) => 'row-' . $closing->id)
            ->addColumn('closed_html', fn ($closing) => '<a href="' . $this->showRoute($closing->id) . '" data-modal class="font-medium">'
                . $closing->closed_at?->format('d-m-Y h:i A') . '</a>')
            ->addColumn('period', fn ($closing) => view('paymentmodule::Shift.partials.period', compact('closing'))->render())
            // the payments' dates (one date, or first → last) + how many
            ->addColumn('payments_html', fn ($closing) => view('paymentmodule::Shift.partials.payment-dates', compact('closing'))->render())
            ->addColumn('status_html', fn ($closing) => view('paymentmodule::Shift.partials.status', compact('closing'))->render())
            // money colors of the shift totals (custom.css .money-*): collected green, cash out red, 0 grey, net green bold (red < 0)
            ->editColumn('cash_total', fn ($closing) => '<span class="' . ($closing->cash_total ? 'money-in' : 'money-zero') . '">' . number_format($closing->cash_total, 2) . '</span>')
            ->editColumn('withholding_total', fn ($closing) => '<span class="' . ($closing->withholding_total ? 'money-withholding' : 'money-zero') . '">' . number_format($closing->withholding_total, 2) . '</span>')
            ->editColumn('expenses_total', fn ($closing) => $closing->expenses_total ? '<span class="money-out">' . number_format(-$closing->expenses_total, 2) . '</span>' : '<span class="money-zero">0.00</span>')
            // what was handed over: collected - expenses
            ->addColumn('net_total', fn ($closing) => '<span class="money-net' . ($closing->net_total < 0 ? ' money-net--negative' : '') . '">' . number_format($closing->net_total, 2) . '</span>')
            ->rawColumns(['closed_html', 'period', 'payments_html', 'status_html', 'cash_total', 'withholding_total', 'expenses_total', 'net_total'])
            ->only(['DT_RowId', 'closed_html', 'period', 'payments_html', 'status_html', 'cash_total', 'expenses_total', 'net_total', 'withholding_total'])
            ->toJson();
    }

    // the confirmation popup: the totals to hand over + "Confirm closing" / "Cancel" (?logout=1: the employee's logout popup)
    public function confirm(Request $request)
    {
        return view('paymentmodule::Shift.confirm', [
            'period' => $this->shiftService->openPeriod($this->accountId(), Auth::id()),
            'logout' => $this->area() === 'employee' && $request->boolean('logout'),
        ]);
    }

    public function close(ShiftCloseRequest $request)
    {
        $logout = $this->area() === 'employee' && $request->boolean('logout');
        $closing = $this->shiftService->close($this->accountId(), Auth::user(), (int) $request->validated('up_to_id'), Auth::user(),
            $logout ? 'logout_closed' : 'close', (int) $request->validated('up_to_expense_id'));

        return response()->json([
            'message' => 'Your shift has been closed: ' . number_format($closing->net_total, 2) . ' to hand over.',
            // the logout popup: logged out after closing (custom.js submits the logout form)
            'logout' => $logout,
            'redirect' => route($this->area() . '.shift.index'),
        ]);
    }
}
