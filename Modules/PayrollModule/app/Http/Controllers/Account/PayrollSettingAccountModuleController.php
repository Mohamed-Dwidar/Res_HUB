<?php

namespace Modules\PayrollModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\PayrollModule\app\Http\Requests\AdjustmentTypeRequest;
use Modules\PayrollModule\app\Http\Requests\AllowanceTypeRequest;
use Modules\PayrollModule\app\Http\Requests\PayrollSettingRequest;
use Modules\PayrollModule\app\Services\PayrollSettingService;
use Yajra\DataTables\Facades\DataTables;

/**
 * The payroll setup of the HR sidebar section (the account only): HR › Allowance Types (list, add / edit popups, active switch,
 * delete refused while used) and HR › Payroll Settings (one page). Saves answer `reload`.
 */
class PayrollSettingAccountModuleController extends Controller
{
    public function __construct(private PayrollSettingService $settingService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    // ------------------------------------------------------------------ allowance types

    public function allowanceTypes()
    {
        return view('payrollmodule::Setting.allowance-types');
    }

    public function typesData()
    {
        return DataTables::eloquent($this->settingService->typesQuery($this->accountId()))
            ->setRowId(fn ($type) => 'row-allowance-' . $type->id)
            ->addColumn('name_html', fn ($type) => '<a href="' . route('account.hr.allowance-types.edit', $type->id) . '" data-modal class="font-medium">' . e($type->name) . '</a>'
                . ($type->name_ar && $type->name_en ? '<div class="text-xs text-slate-500" dir="rtl">' . e($type->name_ar) . '</div>' : ''))
            ->addColumn('insurable', fn ($type) => $type->is_insurable ? '<span class="badge badge-status-info">Insurable</span>' : '<span class="text-slate-400">No</span>')
            ->addColumn('status', fn ($type) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.hr.allowance-types.active', $type->id), 'row' => 'allowance-' . $type->id, 'name' => 'is_active', 'checked' => $type->is_active,
            ])->render())
            ->addColumn('actions', fn ($type) => view('payrollmodule::Setting.partials.allowance-actions', ['allowanceType' => $type])->render())
            ->orderColumn('name_html', fn ($query, $order) => $query->orderByLocalized('name', $order))
            ->rawColumns(['name_html', 'insurable', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'insurable', 'items_count', 'status', 'actions'])
            ->toJson();
    }

    public function typeCreate()
    {
        return view('payrollmodule::Setting.allowance-type-form', ['allowanceType' => null]);
    }

    public function typeStore(AllowanceTypeRequest $request)
    {
        $this->settingService->saveType($this->accountId(), $request->validated());
        return $this->saved('account.hr.allowance-types.index', 'The allowance type has been added.');
    }

    public function typeEdit($id)
    {
        return view('payrollmodule::Setting.allowance-type-form', ['allowanceType' => $this->settingService->findType($this->accountId(), $id)]);
    }

    public function typeUpdate(AllowanceTypeRequest $request, $id)
    {
        $this->settingService->saveType($this->accountId(), $request->validated(), $id);
        return $this->saved('account.hr.allowance-types.index', 'The allowance type has been saved.');
    }

    public function typeActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $type = $this->settingService->setTypeActive($this->accountId(), $id, $request->boolean('is_active'));
        return response()->json(['message' => $type->is_active ? 'The allowance type has been activated.' : 'The allowance type has been deactivated.']);
    }

    public function typeDestroy($id)
    {
        if (!$this->settingService->deleteType($this->accountId(), $id)) {
            return response()->json(['message' => 'Salaries use this allowance type: switch it off instead.'], 422);
        }
        return $this->saved('account.hr.allowance-types.index', 'The allowance type has been deleted.');
    }

    // ------------------------------------------------------------------ adjustment types (H4)

    public function adjustmentTypes()
    {
        return view('payrollmodule::Setting.adjustment-types');
    }

    public function adjustmentTypesData()
    {
        return DataTables::eloquent($this->settingService->adjustmentTypesQuery($this->accountId()))
            ->setRowId(fn ($type) => 'row-adjustment-' . $type->id)
            ->addColumn('name_html', fn ($type) => '<a href="' . route('account.hr.adjustment-types.edit', $type->id) . '" data-modal class="font-medium">' . e($type->name) . '</a>'
                . ($type->name_ar && $type->name_en ? '<div class="text-xs text-slate-500" dir="rtl">' . e($type->name_ar) . '</div>' : ''))
            ->addColumn('kind_html', fn ($type) => '<span class="badge ' . ($type->kind === 'bonus' ? 'badge-status-success' : 'badge-status-danger') . '">' . e($type->kind_label) . '</span>')
            ->addColumn('status', fn ($type) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.hr.adjustment-types.active', $type->id), 'row' => 'adjustment-' . $type->id, 'name' => 'is_active', 'checked' => $type->is_active,
            ])->render())
            ->addColumn('actions', fn ($type) => view('payrollmodule::Setting.partials.adjustment-actions', ['adjustmentType' => $type])->render())
            ->orderColumn('name_html', fn ($query, $order) => $query->orderByLocalized('name', $order))
            ->rawColumns(['name_html', 'kind_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'kind_html', 'adjustments_count', 'status', 'actions'])
            ->toJson();
    }

    public function adjustmentTypeCreate()
    {
        return view('payrollmodule::Setting.adjustment-type-form', ['adjustmentType' => null]);
    }

    public function adjustmentTypeStore(AdjustmentTypeRequest $request)
    {
        $this->settingService->saveAdjustmentType($this->accountId(), $request->validated());
        return $this->saved('account.hr.adjustment-types.index', 'The type has been added.');
    }

    public function adjustmentTypeEdit($id)
    {
        return view('payrollmodule::Setting.adjustment-type-form', ['adjustmentType' => $this->settingService->findAdjustmentType($this->accountId(), $id)]);
    }

    public function adjustmentTypeUpdate(AdjustmentTypeRequest $request, $id)
    {
        $this->settingService->saveAdjustmentType($this->accountId(), $request->validated(), $id);
        return $this->saved('account.hr.adjustment-types.index', 'The type has been saved.');
    }

    public function adjustmentTypeActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $type = $this->settingService->setAdjustmentTypeActive($this->accountId(), $id, $request->boolean('is_active'));
        return response()->json(['message' => $type->is_active ? 'The type has been activated.' : 'The type has been deactivated.']);
    }

    public function adjustmentTypeDestroy($id)
    {
        if (!$this->settingService->deleteAdjustmentType($this->accountId(), $id)) {
            return response()->json(['message' => 'Bonuses / deductions use this type: switch it off instead.'], 422);
        }
        return $this->saved('account.hr.adjustment-types.index', 'The type has been deleted.');
    }

    // ------------------------------------------------------------------ payroll settings

    public function settingsEdit()
    {
        return view('payrollmodule::Setting.payroll-settings', ['settings' => $this->settingService->settings($this->accountId())]);
    }

    public function settingsUpdate(PayrollSettingRequest $request)
    {
        $this->settingService->saveSettings($this->accountId(), $request->validated());
        return $this->saved('account.hr.payroll-settings.edit', 'The payroll settings have been saved.');
    }

    private function saved(string $page, string $message)
    {
        return response()->json(['message' => $message, 'reload' => true, 'redirect' => route($page)]);
    }
}
