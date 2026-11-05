<?php

namespace Modules\HrModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\HrModule\app\Http\Requests\DepartmentRequest;
use Modules\HrModule\app\Http\Requests\DocumentTypeRequest;
use Modules\HrModule\app\Services\HrSettingService;
use Yajra\DataTables\Facades\DataTables;

/**
 * The HR setup pages of the sidebar's HR section (the account only for now, user 2026-11-05): Employee Departments and
 * Document Types, each a list with add / edit popups, an active switch and delete (refused while used). Saves answer `reload`.
 */
class HrSettingAccountModuleController extends Controller
{
    public function __construct(private HrSettingService $settingService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    public function departments()
    {
        return view('hrmodule::Setting.departments');
    }

    public function documentTypes()
    {
        return view('hrmodule::Setting.document-types');
    }

    // ------------------------------------------------------------------ departments

    public function departmentsData()
    {
        return DataTables::eloquent($this->settingService->departmentsQuery($this->accountId()))
            ->setRowId(fn ($department) => 'row-department-' . $department->id)
            ->addColumn('name_html', fn ($department) => '<a href="' . route('account.hr.departments.edit', $department->id) . '" data-modal class="font-medium">' . e($department->name) . '</a>'
                . ($department->name_ar && $department->name_en ? '<div class="text-xs text-slate-500" dir="rtl">' . e($department->name_ar) . '</div>' : ''))
            ->addColumn('status', fn ($department) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.hr.departments.active', $department->id), 'row' => 'department-' . $department->id, 'name' => 'is_active', 'checked' => $department->is_active,
            ])->render())
            ->addColumn('actions', fn ($department) => view('hrmodule::Setting.partials.department-actions', compact('department'))->render())
            ->orderColumn('name_html', fn ($query, $order) => $query->orderByLocalized('name', $order))
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'staff_count', 'status', 'actions'])
            ->toJson();
    }

    public function departmentCreate()
    {
        return view('hrmodule::Setting.department-form', ['department' => null]);
    }

    public function departmentStore(DepartmentRequest $request)
    {
        $this->settingService->saveDepartment($this->accountId(), $request->validated());
        return $this->saved('account.hr.departments.index', 'The department has been added.');
    }

    public function departmentEdit($id)
    {
        return view('hrmodule::Setting.department-form', ['department' => $this->settingService->findDepartment($this->accountId(), $id)]);
    }

    public function departmentUpdate(DepartmentRequest $request, $id)
    {
        $this->settingService->saveDepartment($this->accountId(), $request->validated(), $id);
        return $this->saved('account.hr.departments.index', 'The department has been saved.');
    }

    public function departmentActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $department = $this->settingService->setDepartmentActive($this->accountId(), $id, $request->boolean('is_active'));
        return $this->saved('account.hr.departments.index', $department->is_active ? 'The department has been activated.' : 'The department has been deactivated.');
    }

    public function departmentDestroy($id)
    {
        if (!$this->settingService->deleteDepartment($this->accountId(), $id)) {
            return response()->json(['message' => 'Job titles use this department: change them first (HR › Employee Positions), or switch it off.'], 422);
        }
        return $this->saved('account.hr.departments.index', 'The department has been deleted.');
    }

    // ------------------------------------------------------------------ document types

    public function typesData()
    {
        $query = $this->settingService->typesQuery($this->accountId());
        if (!request()->filled('order')) {
            $query->ordered();
        }
        return DataTables::eloquent($query)
            ->setRowId(fn ($type) => 'row-type-' . $type->id)
            ->addColumn('name_html', fn ($type) => '<a href="' . route('account.hr.document-types.edit', $type->id) . '" data-modal class="font-medium">' . e($type->name) . '</a>'
                . ($type->name_ar && $type->name_en ? '<div class="text-xs text-slate-500" dir="rtl">' . e($type->name_ar) . '</div>' : ''))
            ->addColumn('expiry', fn ($type) => $type->has_expiry ? 'Yes · remind ' . $type->remind_days . ' days before' : '<span class="text-slate-400">No</span>')
            ->addColumn('required', fn ($type) => $type->is_required ? '<span class="badge badge-status-warning">Required</span>' : '<span class="text-slate-400">No</span>')
            ->addColumn('status', fn ($type) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.hr.document-types.active', $type->id), 'row' => 'type-' . $type->id, 'name' => 'is_active', 'checked' => $type->is_active,
            ])->render())
            ->addColumn('actions', fn ($type) => view('hrmodule::Setting.partials.type-actions', ['documentType' => $type])->render())
            ->orderColumn('name_html', fn ($query, $order) => $query->orderByLocalized('name', $order))
            ->rawColumns(['name_html', 'expiry', 'required', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'expiry', 'required', 'documents_count', 'status', 'actions'])
            ->toJson();
    }

    public function typeCreate()
    {
        return view('hrmodule::Setting.type-form', ['documentType' => null]);
    }

    public function typeStore(DocumentTypeRequest $request)
    {
        $this->settingService->saveType($this->accountId(), $request->validated());
        return $this->saved('account.hr.document-types.index', 'The document type has been added.');
    }

    public function typeEdit($id)
    {
        return view('hrmodule::Setting.type-form', ['documentType' => $this->settingService->findType($this->accountId(), $id)]);
    }

    public function typeUpdate(DocumentTypeRequest $request, $id)
    {
        $this->settingService->saveType($this->accountId(), $request->validated(), $id);
        return $this->saved('account.hr.document-types.index', 'The document type has been saved.');
    }

    public function typeActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $type = $this->settingService->setTypeActive($this->accountId(), $id, $request->boolean('is_active'));
        return $this->saved('account.hr.document-types.index', $type->is_active ? 'The document type has been activated.' : 'The document type has been deactivated.');
    }

    public function typeDestroy($id)
    {
        if (!$this->settingService->deleteType($this->accountId(), $id)) {
            return response()->json(['message' => 'Staff documents use this type: switch it off instead.'], 422);
        }
        return $this->saved('account.hr.document-types.index', 'The document type has been deleted.');
    }

    private function saved(string $page, string $message)
    {
        return response()->json(['message' => $message, 'reload' => true, 'redirect' => route($page)]);
    }
}
