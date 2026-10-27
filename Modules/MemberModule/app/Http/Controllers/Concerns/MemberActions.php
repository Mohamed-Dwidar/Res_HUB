<?php

namespace Modules\MemberModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\CompanyModule\app\Services\CompanyService;
use Modules\JobModule\app\Services\JobService;
use Modules\MemberModule\app\Http\Requests\MemberRequest;
use Modules\MemberModule\app\Services\MemberService;
use Modules\ReservationModule\app\Models\ReservationStatus;
use Yajra\DataTables\Facades\DataTables;

/**
 * Members actions shared by the account and the employee controllers (2026-10-14: employees manage the account's members):
 * list, view, add, edit. Delete = the account only (MemberAccountModuleController::destroy(): members are deleted for good).
 * The controller gives area(): "account" | "employee" (route names {area}.members.*), the views read it from the route.
 * show / create / edit open in the popup (ajax), store / update answer JSON (custom.js ajax forms).
 */
trait MemberActions
{
    private $memberService;
    private $companyService;
    private $jobService;

    abstract protected function area(): string;

    public function __construct(MemberService $memberService, CompanyService $companyService, JobService $jobService)
    {
        $this->memberService = $memberService;
        $this->companyService = $companyService;
        $this->jobService = $jobService;
    }

    // the account itself, or the account of the logged in employee
    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // companies / jobs dropdowns
    private function formOptions(): array
    {
        return [
            'companies' => $this->companyService->options($this->accountId()),
            'jobs' => $this->jobService->options($this->accountId()),
        ];
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('membermodule::Account.index', $this->formOptions());
    }

    // DataTables server side: search (search.value) + company_id / job_id filters
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        // + how many reservations (not deleted, counted: no cancelled / pending ..., user 2026-10-27), one subquery
        $query = $this->memberService->listQuery($this->accountId())->withCount(['reservations' => fn ($query) => $query->counted()]);
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            // inside filter() so "of N total" counts all the members
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['company_id', 'job_id'])))
            ->setRowId(fn ($member) => 'row-' . $member->id)
            ->addColumn('name_html', fn ($member) => view('membermodule::Account.partials.row-name', compact('member'))->render())
            ->addColumn('company', fn ($member) => $member->company?->name ?? '-')
            ->addColumn('job', fn ($member) => $member->job?->name ?? '-')
            // the count → the reservations list of this member (All tab)
            ->addColumn('reservations_html', fn ($member) => $member->reservations_count
                ? '<a href="' . route($this->area() . '.reservations.index', ['member_id' => $member->id]) . '" class="font-medium">' . $member->reservations_count . '</a>'
                : '<span class="text-slate-400">0</span>')
            ->editColumn('created_at', fn ($member) => $member->created_at->format('Y-m-d'))
            ->addColumn('actions', fn ($member) => view('membermodule::Account.partials.row-actions', compact('member'))->render())
            ->rawColumns(['name_html', 'reservations_html', 'actions'])
            // send only the table columns (not all the member fields)
            ->only(['DT_RowId', 'name_html', 'company', 'job', 'reservations_html', 'created_at', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('membermodule::Account.create', $this->formOptions());
    }

    // the Member drop menus of the forms (member-select partial, Tom Select load): ?q= (2+ characters), 20 at most
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        return response()->json(mb_strlen($q) < 2 ? [] : $this->memberService->search($this->accountId(), $q));
    }

    // the quick add duplicate check: ?phone= → {member: {id, name, phone, text}} or {member: null} (same account, by phone_key)
    public function lookup(Request $request)
    {
        $member = $this->memberService->findByPhone($this->accountId(), (string) $request->query('phone', ''));
        return response()->json(['member' => $member ? [
            'id' => $member->id, 'name' => $member->name, 'phone' => $member->phone, 'text' => $member->name . ' ' . $member->phone,
        ] : null]);
    }

    public function store(MemberRequest $request)
    {
        $member = $this->memberService->create($this->accountId(), $request->validated(), Auth::user());

        // quick add from a form's Member field (?quick=1): the new option, the form stays open
        if ($request->boolean('quick')) {
            return response()->json([
                'message' => 'The member has been added.',
                'member' => ['id' => $member->id, 'text' => $member->name . ' ' . $member->phone],
            ]);
        }

        return response()->json([
            'message' => 'The member has been added successfully.',
            'reload' => true,
            'redirect' => route($this->area() . '.members.show', $member->id),
        ]);
    }

    public function show($id)
    {
        $member = $this->memberService->findOne($this->accountId(), $id);
        // the top block: Confirmed (counted) | Cancelled (not counted: cancelled, pending ...) reservations, not deleted
        $counts = $member->reservations()->toBase()
            ->selectRaw('SUM(reservation_status_id NOT IN (' . implode(',', array_map('intval', ReservationStatus::notCountedIds() ?: [0])) . ')) AS confirmed, COUNT(*) AS total')
            ->first();
        $confirmed = (int) $counts->confirmed;
        $cancelled = (int) $counts->total - $confirmed;
        return view('membermodule::Account.show', compact('member', 'confirmed', 'cancelled'));
    }

    public function edit($id)
    {
        $member = $this->memberService->findOne($this->accountId(), $id);
        return view('membermodule::Account.edit', compact('member') + $this->formOptions());
    }

    public function update(MemberRequest $request, $id)
    {
        $this->memberService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The member has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route($this->area() . '.members.show', $id),
        ]);
    }
}
