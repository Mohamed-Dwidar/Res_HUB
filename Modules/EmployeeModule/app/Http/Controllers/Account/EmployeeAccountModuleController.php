<?php

namespace Modules\EmployeeModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\EmployeeModule\app\Http\Requests\EmployeeRequest;
use Modules\EmployeeModule\app\Services\EmployeePositionService;
use Modules\EmployeeModule\app\Services\EmployeeService;
use Modules\EmployeeModule\app\Services\EmployeeShiftService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: manage his employees (profile + login), the employee himself can only change his password
// show / create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class EmployeeAccountModuleController extends Controller
{
    private $employeeService;
    private $positionService;
    private $shiftService;

    public function __construct(EmployeeService $employeeService, EmployeePositionService $positionService, EmployeeShiftService $shiftService)
    {
        $this->employeeService = $employeeService;
        $this->positionService = $positionService;
        $this->shiftService = $shiftService;
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    // positions / shifts dropdowns: the active ones + the employee's current one
    private function formOptions($employee = null): array
    {
        return [
            'positions' => $this->positionService->options($this->accountId(), $employee?->position_id),
            'shifts' => $this->shiftService->options($this->accountId(), $employee?->shift_id),
        ];
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('employeemodule::Account.Employee.index', [
            'positions' => $this->positionService->filterOptions($this->accountId()),
            'shifts' => $this->shiftService->filterOptions($this->accountId()),
        ]);
    }

    // DataTables server side: search + position_id / shift_id / status (working | left) filters
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        $query = $this->employeeService->listQuery($this->accountId());
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            // inside filter() so "of N total" counts all the employees
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['position_id', 'shift_id', 'status'])))
            ->setRowId(fn ($employee) => 'row-' . $employee->id)
            ->addColumn('name_html', fn ($employee) => view('employeemodule::Account.Employee.partials.row-name', compact('employee'))->render())
            ->addColumn('position', fn ($employee) => e($employee->position?->name ?? '-'))
            ->addColumn('shift', fn ($employee) => e($employee->shift ? $employee->shift->name . ' (' . $employee->shift->time_range . ')' : '-'))
            ->editColumn('join_date', fn ($employee) => $employee->join_date?->format('Y-m-d') ?? '-')
            ->addColumn('status', fn ($employee) => view('employeemodule::Account.Employee.partials.status-badge', compact('employee'))->render())
            ->addColumn('actions', fn ($employee) => view('employeemodule::Account.Employee.partials.row-actions', compact('employee'))->render())
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'phone', 'position', 'shift', 'join_date', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('employeemodule::Account.Employee.create', $this->formOptions());
    }

    public function store(EmployeeRequest $request)
    {
        $employee = $this->employeeService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The employee has been added successfully, he can log in with his email and password.',
            'reload' => true,
            'redirect' => route('account.employees.show', $employee->id),
        ]);
    }

    public function show($id)
    {
        $employee = $this->employeeService->findOne($this->accountId(), $id);
        return view('employeemodule::Account.Employee.show', compact('employee'));
    }

    public function edit($id)
    {
        $employee = $this->employeeService->findOne($this->accountId(), $id);
        return view('employeemodule::Account.Employee.edit', compact('employee') + $this->formOptions($employee));
    }

    public function update(EmployeeRequest $request, $id)
    {
        $this->employeeService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The employee has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.employees.show', $id),
        ]);
    }

    // Mark Left / Undo Left (the row menu: the updated row; the employee view: back_to_view → the view again)
    public function left(Request $request, $id)
    {
        return $this->leftAnswer($request, $id, true, 'The employee has been marked as left, he can not log in any more.');
    }

    public function undoLeft(Request $request, $id)
    {
        return $this->leftAnswer($request, $id, false, 'The employee is working again, he can log in.');
    }

    private function leftAnswer(Request $request, $id, bool $left, string $message)
    {
        $this->employeeService->setLeft($this->accountId(), $id, $left);

        return response()->json(['message' => $message, 'row' => $this->row($request, $id)]
            + ($request->boolean('back_to_view') ? ['modal' => route('account.employees.show', $id)] : []));
    }

    public function destroy($id)
    {
        $this->employeeService->deleteOne($this->accountId(), $id);

        return response()->json([
            'message' => 'The employee has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.employees.index'),
        ]);
    }

    // the attachments are private files: only the employee's account can download them
    public function downloadAttachment($id, $attachmentId)
    {
        $attachment = $this->employeeService->findAttachment($this->accountId(), $id, $attachmentId);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->download_name);
    }
}
