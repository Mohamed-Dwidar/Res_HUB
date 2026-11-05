<?php

namespace Modules\PayrollModule\app\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\PayrollModule\app\Http\Requests\InsuranceRequest;
use Modules\PayrollModule\app\Http\Requests\SalaryRequest;
use Modules\PayrollModule\app\Services\SalaryService;

/**
 * The Salary tab's popups (docs/hr-plan.md §3.3, H2), shared by the account and the employee controllers: add / edit / delete a
 * salary row, edit the insurance. Routes need hr.salary. A save goes back to the staff page's Salary tab. area() = account | employee.
 */
trait SalaryActions
{
    abstract protected function area(): string;

    public function __construct(private SalaryService $salaryService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    private function tabUrl($staff): string
    {
        return route($this->area() . '.hr.staff.show', [$staff->id, 'tab' => 'salary']);
    }

    // ?from=<id>: a raise pre-filled from that row (new date empty)
    public function salaryCreate($id)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        $from = request('from') ? $this->salaryService->find($staff, request('from')) : null;
        return view('payrollmodule::Salary.form', [
            'staff' => $staff, 'salary' => null, 'from' => $from, 'area' => $this->area(),
            'types' => $this->salaryService->allowanceTypes($this->accountId(), $from),
        ]);
    }

    public function salaryStore(SalaryRequest $request, $id)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        $this->salaryService->create($staff, $request->validated(), Auth::user());
        return response()->json(['message' => 'The salary has been added.', 'reload' => true, 'redirect' => $this->tabUrl($staff)]);
    }

    public function salaryEdit($id, $salaryId)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        $salary = $this->salaryService->find($staff, $salaryId);
        return view('payrollmodule::Salary.form', [
            'staff' => $staff, 'salary' => $salary, 'from' => null, 'area' => $this->area(),
            'types' => $this->salaryService->allowanceTypes($this->accountId(), $salary),
        ]);
    }

    public function salaryUpdate(SalaryRequest $request, $id, $salaryId)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        $this->salaryService->update($staff, $salaryId, $request->validated(), Auth::user());
        return response()->json(['message' => 'The salary has been saved.', 'reload' => true, 'redirect' => $this->tabUrl($staff)]);
    }

    public function salaryDestroy($id, $salaryId)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        $this->salaryService->delete($staff, $salaryId, Auth::user());
        return response()->json(['message' => 'The salary row has been deleted.', 'reload' => true, 'redirect' => $this->tabUrl($staff)]);
    }

    public function insuranceEdit($id)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        return view('payrollmodule::Salary.insurance', [
            'staff' => $staff, 'area' => $this->area(),
            'insurance' => $this->salaryService->insurance($staff),
            'insurable' => $this->salaryService->current($this->salaryService->history($staff))?->insurable,
        ]);
    }

    public function insuranceUpdate(InsuranceRequest $request, $id)
    {
        $staff = $this->salaryService->staff($this->accountId(), $id);
        $this->salaryService->saveInsurance($staff, $request->validated(), Auth::user());
        return response()->json(['message' => 'The insurance has been saved.', 'reload' => true, 'redirect' => $this->tabUrl($staff)]);
    }
}
