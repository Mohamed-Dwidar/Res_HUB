<?php

namespace Modules\EmployeeModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\EmployeeModule\app\Http\Requests\RoleRequest;
use Modules\EmployeeModule\app\Models\Role;
use Modules\EmployeeModule\app\Services\RoleService;
use Yajra\DataTables\Facades\DataTables;

/**
 * Settings › Roles (docs/roles-plan.md, R0): what each employee may do. The owner only (settings, employees, roles, the Access
 * Log and his own My Shift are never in a role). Add (from a preset or a copy: ?preset= / ?from=), edit, delete (not the default
 * role, not a role employees have); add / edit open in the popup.
 */
class RoleAccountModuleController extends Controller
{
    public function __construct(private RoleService $roleService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    public function index()
    {
        return view('employeemodule::Account.Role.index');
    }

    public function data(Request $request)
    {
        $search = trim((string) $request->input('search.value'));
        $total = count($this->roleService->keys());

        return DataTables::eloquent($this->roleService->listQuery($this->accountId()))
            ->filter(fn ($query) => $query->filter(['search' => $search]))
            ->setRowId(fn ($role) => 'row-' . $role->id)
            ->addColumn('name_html', fn ($role) => view('employeemodule::Account.Role.partials.row-name', compact('role'))->render())
            ->addColumn('permissions_count', fn ($role) => count((array) $role->permissions) . ' of ' . $total)
            ->addColumn('idle', fn ($role) => $role->idle_hours ? $role->idle_hours . ($role->idle_hours === 1 ? ' hour' : ' hours') : 'No limit')
            ->addColumn('actions', fn ($role) => view('employeemodule::Account.Role.partials.row-actions', compact('role'))->render())
            ->rawColumns(['name_html', 'actions'])
            ->only(['DT_RowId', 'name_html', 'permissions_count', 'idle', 'employees_count', 'actions'])
            ->toJson();
    }

    // ?preset= (front_desk default | empty) or ?from=<role id> (Duplicate)
    public function create(Request $request)
    {
        $values = $this->roleService->startValues($this->accountId(), $request->input('preset'), $request->input('from'));
        return view('employeemodule::Account.Role.create', $this->formData(null, $values) + [
            'presets' => $this->roleService->presets(),
            'preset' => $request->input('from') ? null : ($request->input('preset') ?: 'front_desk'),
        ]);
    }

    public function store(RoleRequest $request)
    {
        $this->roleService->create($this->accountId(), $request->validated(), Auth::id());

        return response()->json([
            'message' => 'The role has been added. Give it to employees from their Edit form.',
            'reload' => true,
            'redirect' => route('account.settings.roles.index'),
        ]);
    }

    public function edit($id)
    {
        $role = $this->roleService->findOne($this->accountId(), $id);
        return view('employeemodule::Account.Role.edit', $this->formData($role, [
            'name' => $role->name, 'permissions' => (array) $role->permissions, 'idle_hours' => $role->idle_hours,
        ]));
    }

    public function update(RoleRequest $request, $id)
    {
        $this->roleService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The role has been saved. Its employees get the change on their next page.',
            'reload' => true,
            'redirect' => route('account.settings.roles.index'),
        ]);
    }

    public function destroy($id)
    {
        $this->roleService->deleteOne($this->accountId(), $id);

        return response()->json([
            'message' => 'The role has been deleted.',
            'row' => null,
            'redirect' => route('account.settings.roles.index'),
        ]);
    }

    private function formData(?Role $role, array $values): array
    {
        return [
            'role' => $role,
            'values' => $values,
            'catalog' => $this->roleService->catalog(),
            'requires' => $this->roleService->requires(),
        ];
    }
}
