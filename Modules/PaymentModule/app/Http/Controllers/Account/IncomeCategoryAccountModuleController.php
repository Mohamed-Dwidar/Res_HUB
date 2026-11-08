<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\IncomeCategoryRequest;
use Modules\PaymentModule\app\Services\IncomeCategoryService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: his income categories (other income: payments without a reservation / package; the payment form selects one)
// create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class IncomeCategoryAccountModuleController extends Controller
{
    private $categoryService;

    public function __construct(IncomeCategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('paymentmodule::Category.index');
    }

    // DataTables server side: search + is_active filter
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        $query = $this->categoryService->listQuery($this->accountId());
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['is_active'])))
            ->setRowId(fn ($category) => 'row-' . $category->id)
            ->orderColumn('name', fn ($query, $order) => $query->orderByLocalized('name', $order))
            ->addColumn('name_html', fn ($category) => '<a href="' . route('account.income-categories.edit', $category->id) . '" data-modal class="font-medium">' . e($category->name) . '</a>'
                // a system category (never income) / the Add Extra default price (2026-11-08)
                . ($category->isGenerated() ? ' <span class="badge badge-status-secondary">System</span>' : '')
                . ($category->default_price !== null ? ' <span class="text-xs text-slate-500">· ' . number_format($category->default_price, 2) . '</span>' : ''))
            ->addColumn('status', fn ($category) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.income-categories.active', $category->id), 'row' => $category->id, 'name' => 'is_active', 'checked' => $category->is_active,
            ])->render())
            ->addColumn('actions', fn ($category) => view('paymentmodule::Category.partials.row-actions', compact('category'))->render())
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'payments_count', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('paymentmodule::Category.create');
    }

    public function store(IncomeCategoryRequest $request)
    {
        $this->categoryService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The income category has been added successfully.',
            'reload' => true,
            'redirect' => route('account.income-categories.index'),
        ]);
    }

    public function edit($id)
    {
        $category = $this->categoryService->findOne($this->accountId(), $id);
        return view('paymentmodule::Category.edit', compact('category'));
    }

    public function update(IncomeCategoryRequest $request, $id)
    {
        $this->categoryService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The income category has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.income-categories.index'),
        ]);
    }

    // the active switch in the list (ajax)
    public function toggleActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $category = $this->categoryService->setActive($this->accountId(), $id, $request->boolean('is_active'));

        return response()->json([
            'message' => $category->is_active ? 'The income category has been activated.' : 'The income category has been deactivated.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.income-categories.index'),
        ]);
    }

    public function destroy($id)
    {
        if ($error = $this->categoryService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json([
            'message' => 'The income category has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.income-categories.index'),
        ]);
    }
}
