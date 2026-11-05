<?php

namespace Modules\HrModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\EmployeeModule\app\Services\EmployeePositionService;
use Modules\EmployeeModule\app\Services\EmployeeShiftService;
use Modules\HrModule\app\Http\Requests\StaffDocumentRequest;
use Modules\HrModule\app\Http\Requests\StaffRequest;
use Modules\HrModule\app\Http\Requests\StaffStatusRequest;
use Modules\HrModule\app\Models\Staff;
use Modules\HrModule\app\Services\HrSettingService;
use Modules\HrModule\app\Services\StaffService;
use Yajra\DataTables\Facades\DataTables;

/**
 * Staff files shared by the account and the employee controllers (docs/hr-plan.md H1): the Staff list, ONE staff page with
 * tabs (Profile, Documents; later phases add theirs), add / edit (popup), Change Status (popup), documents (popups + private
 * download). Who may do what = the routes' can: (hr.view, hr.manage, documents.view, documents.manage) + hr.sensitive for the
 * identity / pay / emergency data. An employee opening a staff page / tab and every download (the owner's too) are written in
 * the Access Log. The controller gives area(): "account" | "employee" (route names {area}.hr.staff.*).
 */
trait StaffActions
{
    private $staffService;

    abstract protected function area(): string;

    public function __construct(StaffService $staffService)
    {
        $this->staffService = $staffService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    private function route(string $name, $parameters = []): string
    {
        return route($this->area() . '.hr.staff.' . $name, $parameters);
    }

    // the Staff list (rows from data()): search | department | position | status | documents
    public function index()
    {
        return view('hrmodule::Staff.index', [
            'departments' => app(HrSettingService::class)->departmentFilterOptions($this->accountId()),
            'positions' => app(EmployeePositionService::class)->filterOptions($this->accountId()),
            'alerts' => Gate::allows('documents.view') ? $this->staffService->alerts($this->accountId()) : null,
        ]);
    }

    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // Staff | Work | Phone | Hire Date | Documents | Status | menu
    private function table(array $filters, $id = null)
    {
        $accountId = $this->accountId();
        $query = $this->staffService->listQuery($accountId)->select('employees.*');
        if ($id) {
            $query->whereKey($id);
        }
        if (!request()->filled('order')) {
            $query->orderBy('employees.employee_no');
        }
        $alerts = Gate::allows('documents.view') ? $this->staffService->alerts($accountId) : null;

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($filters, $accountId, $alerts, $id) {
                $query->filter($id ? ['status' => 'all'] : Arr::only($filters, ['search', 'type', 'department_id', 'position_id', 'status']));
                if ($alerts !== null && in_array($filters['documents'] ?? null, ['expired', 'expiring', 'missing'], true)) {
                    $query->whereIn('employees.id', $this->staffService->idsWithAlert($accountId, $filters['documents']));
                }
            })
            ->setRowId(fn ($staff) => 'row-' . $staff->id)
            ->setRowClass(fn ($staff) => $staff->hasEnded() ? 'row-muted' : '')
            ->addColumn('name_html', fn ($staff) => view('hrmodule::Staff.partials.row-name', ['staff' => $staff, 'area' => $this->area()])->render())
            ->addColumn('work_html', fn ($staff) => view('hrmodule::Staff.partials.row-work', compact('staff'))->render())
            ->addColumn('phone_html', fn ($staff) => e($staff->phone ?: '-'))
            ->addColumn('hire', fn ($staff) => $staff->join_date?->format('d-m-Y') ?? '-')
            ->addColumn('documents_html', fn ($staff) => $alerts === null ? '' : view('hrmodule::Staff.partials.row-documents', ['alert' => $alerts['staff'][$staff->id] ?? null])->render())
            ->addColumn('status_html', fn ($staff) => view('hrmodule::Staff.partials.status-badge', compact('staff'))->render())
            ->addColumn('actions', fn ($staff) => view('hrmodule::Staff.partials.row-actions', ['staff' => $staff, 'area' => $this->area()])->render())
            ->orderColumn('name_html', fn ($query, $order) => $query->orderBy('employees.name', $order))
            ->orderColumn('hire', fn ($query, $order) => $query->orderBy('employees.join_date', $order))
            ->rawColumns(['name_html', 'work_html', 'phone_html', 'documents_html', 'status_html', 'actions'])
            ->only(['DT_RowId', 'DT_RowClass', 'name_html', 'work_html', 'phone_html', 'hire', 'documents_html', 'status_html', 'actions']);
    }

    public function create()
    {
        return view('hrmodule::Staff.create', $this->formData(null));
    }

    public function store(StaffRequest $request)
    {
        $staff = $this->staffService->create($this->accountId(), $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The staff file of ' . $staff->name . ' has been added.',
            'redirect' => $this->route('show', $staff->id),
        ]);
    }

    /**
     * The staff page (a full page, docs/hr-plan.md §B): ?tab=profile | documents (a tab the user can't open falls back to
     * Profile). An employee's visit is written in the Access Log (the owner's views are not, user 2026-11-04).
     */
    public function show(Request $request, $id)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        $tabs = ['profile' => 'Profile'] + (Gate::allows('documents.view') ? ['documents' => 'Documents'] : []);
        $tab = array_key_exists($request->query('tab'), $tabs) ? $request->query('tab') : 'profile';
        app(AccessLogService::class)->record('view', Auth::user(), ['page' => 'Staff › ' . $tabs[$tab], 'subject' => $staff]);

        $data = compact('staff', 'tabs', 'tab') + ['area' => $this->area()];
        if ($tab === 'documents') {
            $data['documents'] = $this->staffService->documents($staff);
            $data['alert'] = $staff->hasEnded() ? null : ($this->staffService->alerts($this->accountId())['staff'][$staff->id] ?? null);
        } else {
            $data['events'] = $this->staffService->events($staff);
            $data['access'] = $this->staffService->accessInfo($staff);
        }
        return view('hrmodule::Staff.show', $data);
    }

    public function edit($id)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        return view('hrmodule::Staff.edit', $this->formData($staff));
    }

    public function update(StaffRequest $request, $id)
    {
        $staff = $this->staffService->update($this->accountId(), $id, $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The staff file has been saved.',
            'reload' => true,
            'redirect' => $this->route('show', $staff->id),
        ]);
    }

    public function statusForm($id)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        return view('hrmodule::Staff.status', ['staff' => $staff, 'area' => $this->area()]);
    }

    public function status(StaffStatusRequest $request, $id)
    {
        $staff = $this->staffService->changeStatus($this->accountId(), $id, $request->validated(), Auth::user());

        return response()->json([
            'message' => $staff->name . ': ' . $staff->status_label . '.',
            'reload' => true,
            'redirect' => $this->route('show', $staff->id),
        ]);
    }

    // ------------------------------------------------------------------ documents

    // ?document_type_id= (from the "missing" warning): that type chosen
    public function documentCreate(Request $request, $id)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        return view('hrmodule::Document.create', $this->documentFormData($staff, null) + ['typeId' => (int) $request->query('document_type_id')]);
    }

    public function documentStore(StaffDocumentRequest $request, $id)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        $this->staffService->createDocument($staff, $request->validated(), Auth::user());

        return response()->json(['message' => 'The document has been added.', 'redirect' => $this->route('show', [$staff->id, 'tab' => 'documents'])]);
    }

    public function documentEdit($id, $documentId)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        return view('hrmodule::Document.edit', $this->documentFormData($staff, $this->staffService->findDocument($staff, $documentId)));
    }

    public function documentUpdate(StaffDocumentRequest $request, $id, $documentId)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        $this->staffService->updateDocument($staff, $documentId, $request->validated(), Auth::user());

        return response()->json(['message' => 'The document has been saved.', 'redirect' => $this->route('show', [$staff->id, 'tab' => 'documents'])]);
    }

    public function documentDestroy($id, $documentId)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        $this->staffService->deleteDocument($staff, $documentId, Auth::user());

        return response()->json(['message' => 'The document has been deleted.', 'redirect' => $this->route('show', [$staff->id, 'tab' => 'documents'])]);
    }

    /**
     * One file of a document (private disk): the staff file of THIS account (findOne), its document, its file — else 404; the
     * route needs documents.view. Opened in the browser (?download=1: saved), under its original name; the disk path is never
     * sent. Every open / download is in the Access Log, the owner's too.
     */
    public function documentFile(Request $request, $id, $documentId, $fileId)
    {
        $staff = $this->staffService->findOne($this->accountId(), $id);
        $document = $this->staffService->findDocument($staff, $documentId);
        [$file, $path] = $this->staffService->documentFile($staff, $document, $fileId);
        app(AccessLogService::class)->record('download', Auth::user(), ['page' => 'Staff document · ' . $document->label . ' · ' . $file->original_name, 'subject' => $document], true);

        $name = $file->original_name ?: ('staff-' . $staff->employee_no . '-document.' . pathinfo($path, PATHINFO_EXTENSION));
        return $request->boolean('download')
            ? Storage::disk('local')->download($path, $name)
            : Storage::disk('local')->response($path, $name);
    }

    // ------------------------------------------------------------------ helpers

    private function formData(?Staff $staff): array
    {
        $accountId = $this->accountId();
        return [
            'staff' => $staff,
            'area' => $this->area(),
            // System Access: users.manage, never on one's own file (StaffRequest::canManageAccess() = the same rule)
            'access' => $this->staffService->accessInfo($staff),
            'canAccess' => Gate::allows('users.manage') && !(Auth::user()->userable instanceof \Modules\EmployeeModule\app\Models\Employee
                && $staff?->id === (int) Auth::user()->userable_id),
            'roles' => app(\Modules\EmployeeModule\app\Services\RoleService::class)->options($accountId),
            // Add Staff › Documents (optional)
            'docTypes' => $staff ? [] : $this->staffService->typeOptions($accountId),
            'docTypeIds' => $staff ? [] : $this->staffService->formTypeIds($accountId),
            'defaultRoleId' => app(\Modules\EmployeeModule\app\Services\RoleService::class)->defaultRole($accountId)->id,
            // Department → Job Title (custom.js filterChain); no departments = no Department field, every title
            'departments' => app(HrSettingService::class)->departmentOptions($accountId, $staff?->position?->department_id ?? 0),
            'positions' => app(EmployeePositionService::class)->optionsWithDepartment($accountId, $staff?->position_id),
            'shifts' => app(EmployeeShiftService::class)->options($accountId, $staff?->shift_id),
            'sensitive' => Gate::allows('hr.sensitive'),
        ];
    }

    private function documentFormData(Staff $staff, $document): array
    {
        return [
            'staff' => $staff,
            'document' => $document,
            'area' => $this->area(),
            'types' => $this->staffService->typeOptions($this->accountId(), $document?->document_type_id ?? 0),
            'typeIds' => $this->staffService->formTypeIds($this->accountId()),
        ];
    }
}
