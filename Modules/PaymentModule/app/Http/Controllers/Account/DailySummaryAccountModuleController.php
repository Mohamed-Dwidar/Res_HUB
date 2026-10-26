<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Services\DailySummaryService;
use Modules\PaymentModule\app\Services\ShiftClosingService;

/**
 * The Daily Summary (replaces the day closing, 2026-10-13; docs/payments-plan.md §2b): read only, ?date= (default today).
 * The day's totals per method and per collector (withholding apart), its refunds, the shifts with payments of that day,
 * and every open shift now (Close on Behalf; long open ones flagged with the "still working" answers).
 */
class DailySummaryAccountModuleController extends Controller
{
    private DailySummaryService $summaryService;
    private ShiftClosingService $shiftService;

    public function __construct(DailySummaryService $summaryService, ShiftClosingService $shiftService)
    {
        $this->summaryService = $summaryService;
        $this->shiftService = $shiftService;
    }

    public function index(Request $request)
    {
        $accountId = Auth::user()->userable->ownerAccountId();
        $date = $request->filled('date') && strtotime((string) $request->input('date')) ? Carbon::parse($request->input('date'))->startOfDay() : today();

        return view('paymentmodule::DailySummary.index', [
            'date' => $date,
            'summary' => $this->summaryService->summary($accountId, $date),
            'refunds' => $this->summaryService->refunds($accountId, $date),
            'shifts' => $this->shiftService->closingsForDate($accountId, $date),
            'openPeriods' => $this->shiftService->openPeriods($accountId, $date),
            // the employees' open shifts older than the reminder time (+ their "still working" answers), whatever their dates
            'longOpen' => $this->shiftService->longOpenPeriods(Auth::user()->userable),
        ]);
    }
}
