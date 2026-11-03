<?php

namespace Modules\PlanModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\PlanModule\app\Http\Requests\PlanRequest;
use Modules\PlanModule\app\Services\PlanService;
use Modules\SpaceModule\app\Services\SpaceService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: manage his plans (assigned to spaces / units from their forms)
// show / create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class PlanAccountModuleController extends Controller
{
    private $planService;
    private $spaceService;

    public function __construct(PlanService $planService, SpaceService $spaceService)
    {
        $this->planService = $planService;
        $this->spaceService = $spaceService;
    }

    private function accountId()
    {
        return Auth::user()->userable_id;
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        $spaces = $this->spaceService->filterOptions($this->accountId());
        // the Taxes column only while VAT is on (like the plan form / view)
        $taxesOn = (bool) \Modules\AccountModule\app\Models\Account::current()?->vatEnabled();
        return view('planmodule::Account.index', compact('spaces', 'taxesOn'));
    }

    // DataTables server side: search + space_id / is_active filters
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        $query = $this->planService->listQuery($this->accountId());
        if ($id) {
            $query->whereKey($id);
        }

        $taxAccount = \Modules\AccountModule\app\Models\Account::current();

        return DataTables::eloquent($query)
            // inside filter() so "of N total" counts all the plans
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['space_id', 'is_active'])))
            ->setRowId(fn ($plan) => 'row-' . $plan->id)
            ->addColumn('name_html', fn ($plan) => '<a href="' . route('account.plans.show', $plan->id) . '" data-modal class="font-medium">' . e($plan->name) . '</a>')
            ->addColumn('lease_period_label', fn ($plan) => $plan->lease_period_label ?? '-')
            ->editColumn('capacity', fn ($plan) => $plan->capacity ?: '-')
            ->editColumn('amount', fn ($plan) => number_format((float) $plan->amount, 2))
            ->addColumn('status', fn ($plan) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.plans.active', $plan->id), 'row' => $plan->id, 'name' => 'is_active', 'checked' => $plan->is_active,
            ])->render())
            ->addColumn('taxes', fn ($plan) => view('planmodule::Account.partials.row-taxes', ['plan' => $plan, 'taxAccount' => $taxAccount])->render())
            ->addColumn('actions', fn ($plan) => view('planmodule::Account.partials.row-actions', compact('plan'))->render())
            ->rawColumns(['name_html', 'taxes', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'lease_period_label', 'capacity', 'amount', 'spaces_count', 'units_count', 'taxes', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('planmodule::Account.create');
    }

    public function store(PlanRequest $request)
    {
        $plan = $this->planService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The plan has been added successfully.',
            'reload' => true,
            'redirect' => route('account.plans.show', $plan->id),
        ]);
    }

    public function show($id)
    {
        $plan = $this->planService->findOne($this->accountId(), $id);
        return view('planmodule::Account.show', compact('plan'));
    }

    public function edit($id)
    {
        $plan = $this->planService->findOne($this->accountId(), $id);
        return view('planmodule::Account.edit', compact('plan'));
    }

    public function update(PlanRequest $request, $id)
    {
        $this->planService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The plan has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.plans.show', $id),
        ]);
    }

    // the active switch in the list (ajax)
    public function toggleActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $plan = $this->planService->setActive($this->accountId(), $id, $request->boolean('is_active'));

        return response()->json([
            'message' => $plan->is_active ? 'The plan has been activated.' : 'The plan has been deactivated.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.plans.show', $id),
        ]);
    }

    public function destroy($id)
    {
        $this->planService->deleteOne($this->accountId(), $id);

        return response()->json([
            'message' => 'The plan has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.plans.index'),
        ]);
    }
}
