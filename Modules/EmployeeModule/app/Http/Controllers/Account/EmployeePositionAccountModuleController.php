<?php

namespace Modules\EmployeeModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\EmployeeModule\app\Http\Requests\EmployeePositionRequest;
use Modules\EmployeeModule\app\Services\EmployeePositionService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: his employee positions list (the employee form selects one)
// create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class EmployeePositionAccountModuleController extends Controller
{
    private $positionService;

    public function __construct(EmployeePositionService $positionService)
    {
        $this->positionService = $positionService;
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('employeemodule::Account.Position.index', ['departments' => $this->departments()]);
    }

    // DataTables server side: search + is_active filter
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        $query = $this->positionService->listQuery($this->accountId())->with('department');
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['is_active'])))
            ->setRowId(fn ($position) => 'row-' . $position->id)
            ->addColumn('name_html', fn ($position) => '<a href="' . route('account.employee-positions.edit', $position->id) . '" data-modal class="font-medium">' . e($position->name) . '</a>')
            ->addColumn('department', fn ($position) => e($position->department?->name ?? '-'))
            ->addColumn('status', fn ($position) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.employee-positions.active', $position->id), 'row' => $position->id, 'name' => 'is_active', 'checked' => $position->is_active,
            ])->render())
            ->addColumn('actions', fn ($position) => view('employeemodule::Account.Position.partials.row-actions', compact('position'))->render())
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'department', 'employees_count', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('employeemodule::Account.Position.create', ['departments' => $this->departments()]);
    }

    public function store(EmployeePositionRequest $request)
    {
        $this->positionService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The position has been added successfully.',
            'reload' => true,
            'redirect' => route('account.employee-positions.index'),
        ]);
    }

    public function edit($id)
    {
        $position = $this->positionService->findOne($this->accountId(), $id);
        return view('employeemodule::Account.Position.edit', ['position' => $position, 'departments' => $this->departments($position->department_id)]);
    }

    public function update(EmployeePositionRequest $request, $id)
    {
        $this->positionService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The position has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.employee-positions.index'),
        ]);
    }

    // the active switch in the list (ajax)
    public function toggleActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $position = $this->positionService->setActive($this->accountId(), $id, $request->boolean('is_active'));

        return response()->json([
            'message' => $position->is_active ? 'The position has been activated.' : 'The position has been deactivated.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.employee-positions.index'),
        ]);
    }

    public function destroy($id)
    {
        if (!$this->positionService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => 'This position is used by users, change their position first.'], 422);
        }

        return response()->json([
            'message' => 'The position has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.employee-positions.index'),
        ]);
    }

    // HrModule departments [id => name] (the active ones + the title's current one); empty = no Department field / column
    private function departments($currentId = 0): array
    {
        return app(\Modules\HrModule\app\Services\HrSettingService::class)->departmentOptions($this->accountId(), $currentId);
    }
}
