<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\ExpenseCategoryRequest;
use Modules\PaymentModule\app\Services\ExpenseCategoryService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: his expense categories (Settings › Expense Categories; the expense form selects one)
// create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class ExpenseCategoryAccountModuleController extends Controller
{
    private $categoryService;

    public function __construct(ExpenseCategoryService $categoryService)
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
        return view('paymentmodule::ExpenseCategory.index');
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
            ->addColumn('name_html', fn ($category) => '<a href="' . route('account.expense-categories.edit', $category->id) . '" data-modal class="font-medium">' . e($category->name) . '</a>'
                . ($category->generated_key ? ' <span class="badge badge-status-slate" title="Used by HR for company Cash Out only; not offered in the Cash Out form">System</span>' : ''))
            ->addColumn('status', fn ($category) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.expense-categories.active', $category->id), 'row' => $category->id, 'name' => 'is_active', 'checked' => $category->is_active,
            ])->render())
            ->addColumn('actions', fn ($category) => view('paymentmodule::ExpenseCategory.partials.row-actions', compact('category'))->render())
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'name_html', 'expenses_count', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('paymentmodule::ExpenseCategory.create');
    }

    public function store(ExpenseCategoryRequest $request)
    {
        $this->categoryService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The expense category has been added successfully.',
            'reload' => true,
            'redirect' => route('account.expense-categories.index'),
        ]);
    }

    public function edit($id)
    {
        $category = $this->categoryService->findOne($this->accountId(), $id);
        return view('paymentmodule::ExpenseCategory.edit', compact('category'));
    }

    public function update(ExpenseCategoryRequest $request, $id)
    {
        $this->categoryService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The expense category has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.expense-categories.index'),
        ]);
    }

    // the active switch in the list (ajax)
    public function toggleActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $category = $this->categoryService->setActive($this->accountId(), $id, $request->boolean('is_active'));

        return response()->json([
            'message' => $category->is_active ? 'The expense category has been activated.' : 'The expense category has been deactivated.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.expense-categories.index'),
        ]);
    }

    public function destroy($id)
    {
        if ($error = $this->categoryService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json([
            'message' => 'The expense category has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.expense-categories.index'),
        ]);
    }
}
