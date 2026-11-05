<?php

namespace Modules\EmployeeModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\EmployeeModule\app\Http\Requests\EmployeeShiftRequest;
use Modules\EmployeeModule\app\Services\EmployeeShiftService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: his employee shifts list (the employee form selects one)
// create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class EmployeeShiftAccountModuleController extends Controller
{
    private $shiftService;

    public function __construct(EmployeeShiftService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('employeemodule::Account.Shift.index');
    }

    // DataTables server side: search + is_active filter
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        $query = $this->shiftService->listQuery($this->accountId());
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['is_active'])))
            ->setRowId(fn ($shift) => 'row-' . $shift->id)
            ->addColumn('name_html', fn ($shift) => '<a href="' . route('account.employee-shifts.edit', $shift->id) . '" data-modal class="font-medium">' . e($shift->name) . '</a>')
            ->addColumn('time_range', fn ($shift) => $shift->time_range)
            ->addColumn('status', fn ($shift) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.employee-shifts.active', $shift->id), 'row' => $shift->id, 'name' => 'is_active', 'checked' => $shift->is_active,
            ])->render())
            ->addColumn('actions', fn ($shift) => view('employeemodule::Account.Shift.partials.row-actions', compact('shift'))->render())
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'time_range', 'employees_count', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('employeemodule::Account.Shift.create');
    }

    public function store(EmployeeShiftRequest $request)
    {
        $this->shiftService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The shift has been added successfully.',
            'reload' => true,
            'redirect' => route('account.employee-shifts.index'),
        ]);
    }

    public function edit($id)
    {
        $shift = $this->shiftService->findOne($this->accountId(), $id);
        return view('employeemodule::Account.Shift.edit', compact('shift'));
    }

    public function update(EmployeeShiftRequest $request, $id)
    {
        $this->shiftService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The shift has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.employee-shifts.index'),
        ]);
    }

    // the active switch in the list (ajax)
    public function toggleActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $shift = $this->shiftService->setActive($this->accountId(), $id, $request->boolean('is_active'));

        return response()->json([
            'message' => $shift->is_active ? 'The shift has been activated.' : 'The shift has been deactivated.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.employee-shifts.index'),
        ]);
    }

    public function destroy($id)
    {
        if (!$this->shiftService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => 'This shift is used by users, change their shift first.'], 422);
        }

        return response()->json([
            'message' => 'The shift has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.employee-shifts.index'),
        ]);
    }
}
