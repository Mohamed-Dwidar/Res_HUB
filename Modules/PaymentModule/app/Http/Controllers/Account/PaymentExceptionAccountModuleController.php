<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Services\PaymentExceptionService;

/**
 * Payment Exceptions (2026-10-27, the account only, read only): ?tab= late | edited | deleted | expenses, ?from= / ?to=
 * (default: this month), ?user_id= (the login who recorded the rows). PaymentExceptionService builds the rows.
 */
class PaymentExceptionAccountModuleController extends Controller
{
    public function __construct(private PaymentExceptionService $exceptionService)
    {
    }

    public function index(Request $request)
    {
        $accountId = Auth::user()->userable->ownerAccountId();
        $date = fn ($name, $default) => $request->filled($name) && strtotime((string) $request->input($name))
            ? Carbon::parse($request->input($name))->startOfDay() : $default;
        $from = $date('from', today()->startOfMonth());
        $to = $date('to', today());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $tab = isset(PaymentExceptionService::TABS[$request->input('tab')]) ? $request->input('tab') : 'late';
        $users = $this->exceptionService->users($accountId);
        $userId = isset($users[(int) $request->input('user_id')]) ? (int) $request->input('user_id') : null;

        return view('paymentmodule::PaymentException.index', [
            'tab' => $tab, 'from' => $from, 'to' => $to, 'userId' => $userId, 'users' => $users,
            'counts' => $this->exceptionService->counts($accountId, $from, $to),
            'rows' => $this->exceptionService->rows($accountId, $tab, $from, $to, $userId),
            'reviewAmount' => Auth::user()->userable->expenseReviewAmount(),
            'lateHours' => Auth::user()->userable->lateEntryHours(),
            'exceptionService' => $this->exceptionService,
        ]);
    }
}
