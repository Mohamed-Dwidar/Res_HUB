<?php

namespace Modules\PaymentModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\PaymentModule\app\Http\Requests\ExpenseRequest;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Services\ExpenseCategoryService;
use Modules\PaymentModule\app\Services\ExpenseService;
use Modules\PaymentModule\app\Services\PaymentMethodService;
use Yajra\DataTables\Facades\DataTables;

/**
 * Expenses actions shared by the account and the employee controllers (docs/payments-plan.md "Expenses"): both record and view
 * expenses (an expense joins the recorder's open shift); edit / delete = the account only (ExpenseAccountModuleController).
 * No page of its own since 2026-10-14 (index() → the Payments page, tab Cash Out = expenses + refunds); data() = the expenses
 * table of My Shift (shift=open) and any expenses list. The list = the payments list rules: the account sees all (tabs Today | This Month | All + date range); an employee sees only
 * their own, ONE day at a time (today by default, no tabs; forced on the server). shift=open (My Shift): the login's open
 * shift only. The controller gives area(): "account" | "employee" (route names {area}.expenses.*).
 */
trait ExpenseActions
{
    private ExpenseService $expenseService;
    private ExpenseCategoryService $categoryService;
    private PaymentMethodService $methodService;

    abstract protected function area(): string;

    public function __construct(ExpenseService $expenseService, ExpenseCategoryService $categoryService, PaymentMethodService $methodService)
    {
        $this->expenseService = $expenseService;
        $this->categoryService = $categoryService;
        $this->methodService = $methodService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // the employee: only their own expenses (null = the account: all)
    private function collectorId()
    {
        return $this->area() === 'employee' ? Auth::id() : null;
    }

    // one expense: an employee opens only their own
    private function findForViewer($id): Expense
    {
        $expense = $this->expenseService->findOne($this->accountId(), $id);
        abort_if($this->collectorId() && (int) $expense->creatable_id !== (int) $this->collectorId(), 404);

        return $expense;
    }

    private function route($name, $parameters = [])
    {
        return route($this->area() . '.expenses.' . $name, $parameters);
    }

    // the old Expenses page (2026-10-14): expenses are listed on the Payments page, tab Cash Out (with the refunds)
    public function index()
    {
        return redirect()->route($this->area() . '.payments.index', ['type' => 'out']);
    }

    // the list tab: today (default, also '') | month | all; employees have no tabs (one day: listFilters())
    private function tab(array $filters): string
    {
        if ($this->collectorId()) {
            return 'all';
        }
        $tab = (string) ($filters['tab'] ?? '');
        return isset(Expense::TABS[$tab]) ? $tab : 'today';
    }

    // search (no., item, notes) + category / method / dates; employees: always ONE day (none / not a date = today)
    private function listFilters(array $filters): array
    {
        if ($this->collectorId()) {
            $day = (string) ($filters['date_from'] ?? '');
            $filters['date_from'] = $filters['date_to'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && strtotime($day) ? $day : today()->toDateString();
        }

        return ['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['expense_category_id', 'method', 'date_from', 'date_to']);
    }

    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    /**
     * The list columns, for data() and one row after a change (row()): Expense (item, no.) | Category | Date (time) | Method |
     * Amount | Created By (account only) | row menu. shift=open (My Shift): the login's open shift, no tab / date / summary.
     */
    private function table(array $filters, $id = null)
    {
        $openShift = ($filters['shift'] ?? '') === 'open';
        $tab = $openShift ? 'all' : $this->tab($filters);
        $query = $this->expenseService->listQuery($this->accountId())->with('creatable.userable')
            ->when($this->collectorId(), fn ($query, $userId) => $query->collectedBy($userId))
            ->when($openShift, fn ($query) => $query->collectedBy(Auth::id())->where('shift_closing_id', 0));
        if ($id) {
            $query->whereKey($id);
        }

        $table = DataTables::eloquent($query)
            ->filter(fn ($query) => $openShift
                ? $query->filter(['search' => trim((string) ($filters['search'] ?? ''))])
                : $query->filter($this->listFilters($filters))->tab($tab))
            ->setRowId(fn ($expense) => 'row-' . $expense->id)
            ->orderColumn('spent_at', fn ($query, $order) => $query->orderBy('spent_at', $order)->orderBy('expense_no', $order))
            ->addColumn('item_html', fn ($expense) => view('paymentmodule::Expense.partials.row-item', compact('expense'))->render())
            ->addColumn('category', fn ($expense) => e($expense->expenseCategory?->name ?? '-'))
            ->editColumn('spent_at', fn ($expense) => view('paymentmodule::Payment.partials.record-date', ['record' => $expense])->render())
            ->addColumn('method', fn ($expense) => e($expense->method_name))
            ->editColumn('amount', fn ($expense) => view('paymentmodule::Expense.partials.row-amount', compact('expense'))->render())
            ->addColumn('by', fn ($expense) => e($expense->creatable?->userable?->displayName() ?? '-'))
            ->addColumn('actions', fn ($expense) => view('paymentmodule::Expense.partials.row-actions', compact('expense'))->render())
            ->rawColumns(['item_html', 'spent_at', 'amount', 'actions'])
            ->only(['DT_RowId', 'item_html', 'category', 'spent_at', 'method', 'amount', 'by', 'actions']);

        if ($id || $openShift) {
            return $table;
        }

        // the summary line above the table (datatables.js json "html" → [data-html]): the total + per method
        $rows = $this->expenseService->listQuery($this->accountId(), $this->listFilters($filters))->tab($tab)
            ->when($this->collectorId(), fn ($query, $userId) => $query->collectedBy($userId))
            ->reorder()->setEagerLoads([])->toBase()->selectRaw('payment_method_id, SUM(amount) total')->groupBy('payment_method_id')->get();
        $methodNames = array_map(fn ($option) => $option['name'], $this->methodService->filterOptions($this->accountId()));
        $summary = [
            'total' => round((float) $rows->sum('total'), 2),
            'methods' => $rows->mapWithKeys(fn ($row) => [($row->payment_method_id ? ($methodNames[$row->payment_method_id] ?? 'Method #' . $row->payment_method_id) : Expense::NO_METHOD) => round((float) $row->total, 2)])->all(),
        ];

        return $table->with('html', ['expenses-summary' => view('paymentmodule::Expense.partials.list-summary', compact('summary'))->render()]);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more (or deleted)
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        $accountId = $this->accountId();
        return view('paymentmodule::Expense.create', [
            'expense' => new Expense(['spent_at' => today()]),
            'categories' => $this->categoryService->options($accountId),
            'methods' => $this->methodService->options($accountId),
            'closedShift' => null,
        ]);
    }

    public function store(ExpenseRequest $request)
    {
        $expense = $this->expenseService->create($this->accountId(), $request->validated(), Auth::user());

        return response()->json([
            'message' => 'Cash Out #' . $expense->expense_no . ' has been added to your shift.',
            'reload' => true,
            'redirect' => $this->route('show', $expense->id),
        ]);
    }

    public function show($id)
    {
        $expense = $this->findForViewer($id);
        return view('paymentmodule::Expense.show', [
            'expense' => $expense,
            'hasAttachment' => (bool) $this->expenseService->attachmentPath($expense),
            'closedShift' => $this->expenseService->closedShiftOf($expense),
        ]);
    }

    // the receipt is a private file: only who can open the expense downloads it
    public function attachment($id)
    {
        $expense = $this->findForViewer($id);
        $path = $this->expenseService->attachmentPath($expense) ?? abort(404);

        return Storage::disk('local')->response($path, 'expense-' . $expense->expense_no . '.' . pathinfo($path, PATHINFO_EXTENSION));
    }
}
