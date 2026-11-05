<?php

namespace Modules\HrModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\HrModule\app\Http\Requests\LeaveBalanceRequest;
use Modules\HrModule\app\Http\Requests\LeaveCancelRequest;
use Modules\HrModule\app\Http\Requests\LeaveRequest;
use Modules\HrModule\app\Services\LeaveService;

/**
 * The Leaves tab's popups (docs/hr-plan.md §3.4, H3), shared by the account and the employee controllers (routes need
 * hr.manage): add / cancel a leave, a person's own yearly days, the leave's file (private, logged in the Access Log like the
 * documents). A save goes back to the tab (of the leave's year). area() = account | employee.
 */
trait LeaveActions
{
    abstract protected function area(): string;

    public function __construct(private LeaveService $leaveService)
    {
    }

    private function staff($id)
    {
        return $this->leaveService->staff(Auth::user()->userable->ownerAccountId(), $id);
    }

    private function saved($staff, string $message, ?int $year = null)
    {
        return response()->json(['message' => $message, 'reload' => true,
            'redirect' => route($this->area() . '.hr.staff.show', [$staff->id, 'tab' => 'leaves'] + ($year ? ['year' => $year] : []))]);
    }

    public function leaveCreate($id)
    {
        $staff = $this->staff($id);
        return view('hrmodule::Leave.create', [
            'staff' => $staff, 'area' => $this->area(),
            'types' => $this->leaveService->types($staff->account_id),
        ]);
    }

    public function leaveStore(LeaveRequest $request, $id)
    {
        $staff = $this->staff($id);
        [$leave, $warnings] = $this->leaveService->create($staff, $request->validated() + ['attachment' => $request->file('attachment')], Auth::user());
        return $this->saved($staff, 'The leave has been added.' . ($warnings ? ' Note: ' . implode(' ', $warnings) : ''), $leave->from_date->year);
    }

    public function leaveCancelForm($id, $leaveId)
    {
        $staff = $this->staff($id);
        $leave = $this->leaveService->find($staff, $leaveId);
        return view('hrmodule::Leave.cancel', ['staff' => $staff, 'leave' => $leave, 'area' => $this->area()]);
    }

    public function leaveCancel(LeaveCancelRequest $request, $id, $leaveId)
    {
        $staff = $this->staff($id);
        $leave = $this->leaveService->cancel($staff, $leaveId, $request->validated('cancel_reason'), Auth::user());
        return $this->saved($staff, 'The leave has been cancelled.', $leave->from_date->year);
    }

    public function balanceForm(Request $request, $id, $typeId)
    {
        $staff = $this->staff($id);
        $year = (int) ($request->query('year') ?: today()->year);
        $card = $this->leaveService->balances($staff, $year)[$typeId] ?? abort(404);
        return view('hrmodule::Leave.balance', ['staff' => $staff, 'card' => $card, 'year' => $year, 'area' => $this->area()]);
    }

    public function balanceUpdate(LeaveBalanceRequest $request, $id, $typeId)
    {
        $staff = $this->staff($id);
        $this->leaveService->setBalance($staff, $typeId, (int) $request->validated('year'), $request->validated('days'), Auth::user());
        return $this->saved($staff, 'The balance has been saved.', (int) $request->validated('year'));
    }

    // the leave's file (opened in the browser; ?download=1 saves it); every open is in the Access Log, the owner's too
    public function leaveFile(Request $request, $id, $leaveId)
    {
        $staff = $this->staff($id);
        $leave = $this->leaveService->find($staff, $leaveId);
        [$path, $name] = $this->leaveService->file($staff, $leave);
        app(AccessLogService::class)->record('download', Auth::user(), ['page' => 'Staff leave file · ' . $name, 'subject' => $staff], true);
        return $request->boolean('download') ? Storage::disk('local')->download($path, $name) : Storage::disk('local')->response($path, $name);
    }
}
