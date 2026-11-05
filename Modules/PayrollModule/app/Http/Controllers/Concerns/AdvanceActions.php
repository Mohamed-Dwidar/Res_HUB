<?php

namespace Modules\PayrollModule\app\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\PayrollModule\app\Http\Requests\AdjustmentRequest;
use Modules\PayrollModule\app\Http\Requests\AdvanceMoveRequest;
use Modules\PayrollModule\app\Http\Requests\AdvanceRequest;
use Modules\PayrollModule\app\Http\Requests\CancelRequest;
use Modules\PayrollModule\app\Services\AdjustmentService;
use Modules\PayrollModule\app\Services\AdvanceService;
use Modules\PayrollModule\app\Services\SalaryService;

/**
 * The "Advances & Adjustments" tab's popups (docs/hr-plan.md §3.5, H4), shared by the account and the employee controllers (routes
 * need hr.salary): give / cancel an advance, a cash repayment / installment change and their cancel, add /
 * cancel a bonus or deduction. A save goes back to the tab. area() = account | employee.
 */
trait AdvanceActions
{
    abstract protected function area(): string;

    public function __construct(private AdvanceService $advanceService, private AdjustmentService $adjustmentService, private SalaryService $salaryService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // the staff file on payroll (External = 404)
    private function staff($id)
    {
        return $this->salaryService->staff($this->accountId(), $id);
    }

    private function saved($staff, string $message)
    {
        return response()->json(['message' => $message, 'reload' => true, 'redirect' => route($this->area() . '.hr.staff.show', [$staff->id, 'tab' => 'advances'])]);
    }

    // ------------------------------------------------------------------ advances

    public function advanceCreate($id)
    {
        $staff = $this->staff($id);
        return view('payrollmodule::Advance.create', [
            'staff' => $staff, 'area' => $this->area(),
            'methods' => $this->advanceService->methodOptions($this->accountId()),
            'months' => $this->advanceService->monthOptions(),
            'salary' => $this->salaryService->current($this->salaryService->history($staff)),
            // the live warning (custom.js advanceWarn()): the open advances' installments per month + the % of the settings
            'monthTotals' => $this->advanceService->monthTotals($this->advanceService->list($staff)),
            'warnPercent' => (float) (\Modules\PayrollModule\app\Models\PayrollSetting::of($this->accountId())->advance_warn_percent ?? 50),
        ]);
    }

    public function advanceStore(AdvanceRequest $request, $id)
    {
        $staff = $this->staff($id);
        $advance = $this->advanceService->give($staff, $request->validated(), Auth::user());
        return $this->saved($staff, 'Advance #' . $advance->advance_no . ' has been given (company Cash Out, not in any shift).');
    }

    public function advanceCancelForm($id, $advanceId)
    {
        $staff = $this->staff($id);
        $advance = $this->advanceService->find($staff, $advanceId);
        return view('payrollmodule::Advance.cancel', [
            'staff' => $staff, 'title' => 'Cancel Advance #' . $advance->advance_no,
            'action' => route($this->area() . '.payroll.advances.cancel', [$staff->id, $advance->id]),
            'text' => 'Advance #' . $advance->advance_no . ' (' . number_format($advance->amount, 2) . ') will be cancelled and its company Cash Out'
                . ($advance->expense ? ' #' . $advance->expense->expense_no : '') . ' voided. Use it for a mistake only.',
        ]);
    }

    public function advanceCancel(CancelRequest $request, $id, $advanceId)
    {
        $staff = $this->staff($id);
        $advance = $this->advanceService->cancel($staff, $advanceId, $request->validated('cancel_reason'), Auth::user());
        return $this->saved($staff, 'Advance #' . $advance->advance_no . ' has been cancelled and its Cash Out voided.');
    }

    // kind = repay | change
    public function moveForm($id, $advanceId, $kind)
    {
        $staff = $this->staff($id);
        $advance = $this->advanceService->find($staff, $advanceId);
        return view('payrollmodule::Advance.move', [
            'staff' => $staff, 'advance' => $advance, 'kind' => $kind, 'area' => $this->area(),
            'remaining' => $this->advanceService->remaining($advance),
            'installment' => $this->advanceService->installmentFor($advance, today()->startOfMonth()->max($advance->first_period)),
            'methods' => $kind === 'repay' ? $this->advanceService->methodOptions($this->accountId()) : [],
            'months' => array_filter($this->advanceService->monthOptions(), fn ($month) => $month >= today()->startOfMonth()->toDateString(), ARRAY_FILTER_USE_KEY),
            'owner' => app(\Modules\PaymentModule\app\Services\PaymentService::class)->isOwner(Auth::user()),
        ]);
    }

    public function moveStore(AdvanceMoveRequest $request, $id, $advanceId, $kind)
    {
        $staff = $this->staff($id);
        $this->advanceService->{$kind}($staff, $advanceId, $request->validated(), Auth::user());
        return $this->saved($staff, [
            'repay' => 'The cash repayment has been recorded (Cash In in your shift).',
            'change' => 'The installment has been changed.',
        ][$kind]);
    }

    public function moveCancelForm($id, $advanceId, $moveId)
    {
        $staff = $this->staff($id);
        $advance = $this->advanceService->find($staff, $advanceId);
        $move = $this->advanceService->findMove($advance, $moveId);
        return view('payrollmodule::Advance.cancel', [
            'staff' => $staff, 'title' => 'Cancel ' . $move->type_label,
            'action' => route($this->area() . '.payroll.advances.moves.cancel', [$staff->id, $advance->id, $move->id]),
            'text' => $move->type_label . ' of ' . number_format($move->amount, 2) . ' (advance #' . $advance->advance_no . ') will be cancelled'
                . ($move->type === 'repay' ? ' and its Cash In #' . ($move->payment?->payment_no ?? '-') . ' deleted.' : '.'),
        ]);
    }

    public function moveCancel(CancelRequest $request, $id, $advanceId, $moveId)
    {
        $staff = $this->staff($id);
        $this->advanceService->cancelMove($staff, $advanceId, $moveId, $request->validated('cancel_reason'), Auth::user());
        return $this->saved($staff, 'Cancelled.');
    }

    // ------------------------------------------------------------------ bonuses / deductions

    public function adjustmentCreate($id)
    {
        $staff = $this->staff($id);
        return view('payrollmodule::Adjustment.create', [
            'staff' => $staff, 'area' => $this->area(),
            'types' => $this->adjustmentService->typeOptions($this->accountId()),
            'months' => $this->adjustmentService->monthOptions(),
        ]);
    }

    public function adjustmentStore(AdjustmentRequest $request, $id)
    {
        $staff = $this->staff($id);
        $this->adjustmentService->create($staff, $request->validated(), Auth::user());
        return $this->saved($staff, 'Saved: it will be applied by the payroll of its month.');
    }

    public function adjustmentCancelForm($id, $adjustmentId)
    {
        $staff = $this->staff($id);
        $adjustment = $this->adjustmentService->find($staff, $adjustmentId);
        return view('payrollmodule::Advance.cancel', [
            'staff' => $staff, 'title' => 'Cancel ' . $adjustment->type?->kind_label,
            'action' => route($this->area() . '.payroll.adjustments.cancel', [$staff->id, $adjustment->id]),
            'text' => ($adjustment->type?->name ?? '-') . ' · ' . $adjustment->period->format('F Y') . ' · ' . $adjustment->valueText() . ' will be cancelled.',
        ]);
    }

    public function adjustmentCancel(CancelRequest $request, $id, $adjustmentId)
    {
        $staff = $this->staff($id);
        $this->adjustmentService->cancel($staff, $adjustmentId, $request->validated('cancel_reason'), Auth::user());
        return $this->saved($staff, 'Cancelled.');
    }
}
