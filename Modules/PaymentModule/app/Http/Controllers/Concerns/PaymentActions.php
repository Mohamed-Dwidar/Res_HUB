<?php

namespace Modules\PaymentModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\CompanyModule\app\Services\CompanyService;
use Modules\PaymentModule\app\Http\Requests\PaymentRefundRequest;
use Modules\PaymentModule\app\Http\Requests\PaymentRequest;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Services\CashListService;
use Modules\PaymentModule\app\Services\ExpenseCategoryService;
use Modules\PaymentModule\app\Services\IncomeCategoryService;
use Modules\PaymentModule\app\Services\PaymentMethodService;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\ReservationModule\app\Models\Reservation;
use niklasravnsborg\LaravelPdf\Facades\Pdf;
use Yajra\DataTables\Facades\DataTables;

/**
 * Payments actions shared by the account and the employee controllers (both add, view and REFUND payments — a refund goes
 * into the refunder's own open shift, 2026-10-13; edit / delete = the account only).
 * An employee's list shows only the payments they added (never colleagues' collections as a whole, payments-plan.md §2b);
 * one payment of a reservation / package opens for everyone (its payments block lists them all, with the collector).
 * The controller gives area(): "account" | "employee" (route names {area}.payments.*), the views read it from the route.
 * show / create open in the popup (ajax), store answers JSON (custom.js ajax forms), receipt() is the PDF,
 * payables() fills the reservation / package drop menu of the form (custom.js [data-options-url]).
 * The page (2026-10-14, docs/payments-plan.md "Cash In / Cash Out"): tabs All (default) | Cash In | Cash Out (?type=);
 * Cash In = this payments list without refunds (table()), All / Cash Out = payments + expenses together (cashData()).
 */
trait PaymentActions
{
    private PaymentService $paymentService;
    private PaymentMethodService $methodService;
    private IncomeCategoryService $categoryService;
    private CompanyService $companyService;
    private CashListService $cashListService;
    private ExpenseCategoryService $expenseCategoryService;

    abstract protected function area(): string;

    public function __construct(PaymentService $paymentService, PaymentMethodService $methodService, IncomeCategoryService $categoryService, CompanyService $companyService,
        CashListService $cashListService, ExpenseCategoryService $expenseCategoryService)
    {
        $this->paymentService = $paymentService;
        $this->methodService = $methodService;
        $this->categoryService = $categoryService;
        $this->companyService = $companyService;
        $this->cashListService = $cashListService;
        $this->expenseCategoryService = $expenseCategoryService;
    }

    // the account itself, or the account of the logged in employee
    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // the employee: only their own payments in the list (null = the account: all)
    private function collectorId()
    {
        return $this->area() === 'employee' ? Auth::id() : null;
    }

    // one payment: an employee opens their own, or one of a reservation / package (its payments block); not a colleague's other income
    private function findForViewer($id)
    {
        $payment = $this->paymentService->findOne($this->accountId(), $id);
        abort_if($this->collectorId() && !$payment->payable_type && (int) $payment->creatable_id !== (int) $this->collectorId(), 404);

        return $payment;
    }

    private function route($name, $parameters = [])
    {
        return route($this->area() . '.payments.' . $name, $parameters);
    }

    // the page: ?type= all (default) | in | out; the rows come from data() (Cash In) / cashData() (All, Cash Out)
    public function index(Request $request)
    {
        $accountId = $this->accountId();
        $type = isset(CashListService::TYPES[$request->input('type')]) ? $request->input('type') : 'all';
        $activeOnly = $this->area() === 'employee'; // employees: only the active methods / categories
        return view('paymentmodule::Payment.index', [
            'type' => $type,
            'methods' => $this->methodService->filterOptions($accountId, $activeOnly),
            // Cash In: the "For" drop menu: Reservations, Packages, then the "Other income" group (each category)
            'categories' => $this->categoryService->filterOptions($accountId, $activeOnly),
            // Cash Out: the category filter (+ Refunds)
            'expenseCategories' => $type === 'out' ? $this->expenseCategoryService->filterOptions($accountId, $activeOnly) : [],
            // ?member_id= (the Members list, the account only): that member's cash in, the All date tab
            'member' => $type === 'in' && !$this->collectorId() && $request->filled('member_id')
                ? \Modules\MemberModule\app\Models\Member::where('account_id', $accountId)->find((int) $request->input('member_id')) : null,
        ]);
    }

    // the list tab: today (default, also '') | month | all (on the payment date); employees have no tabs (one day: listFilters())
    private function tab(array $filters): string
    {
        if ($this->collectorId()) {
            return 'all';
        }
        $tab = (string) ($filters['tab'] ?? '');
        return isset(Payment::TABS[$tab]) ? $tab : 'today';
    }

    // the list filters of a request: search (number, reference, member) + for / method / dates (Payment::scopeFilter)
    private function listFilters(array $filters): array
    {
        // employees: always ONE day (the date from; none / not a date = today), never a period or all their payments
        if ($this->collectorId()) {
            $day = (string) ($filters['date_from'] ?? '');
            $filters['date_from'] = $filters['date_to'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && strtotime($day) ? $day : today()->toDateString();
        }

        // direction=in: the Cash In tab (no refunds, they are Cash Out)
        // member_id: the Members list's Payments (account)
        return ['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['for', 'method', 'date_from', 'date_to', 'direction', 'member_id']);
    }

    // DataTables server side: tab + search + for / method / dates filters, + the summary line of those rows
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    /**
     * The list columns (docs/payments-plan.md "Payments list", 2026-10-13), for data() and for one row after a change (row()):
     * Member (payment no.) | For (the reservation / package / income category) | Date (time) | Method | Amount | By (account only).
     * shift=open (My Shift, 2026-10-13): the SAME list for the logged in login's open shift only — their own payments not closed
     * yet, whatever their dates (no tab / date rule, no summary line).
     */
    private function table(array $filters, $id = null)
    {
        $openShift = ($filters['shift'] ?? '') === 'open';
        $tab = $openShift ? 'all' : $this->tab($filters);
        $query = $this->paymentService->listQuery($this->accountId())
            ->with('creatable.userable')
            // the reservation's place + start (the For column)
            ->with(['payable' => fn ($morph) => $morph->morphWith([Reservation::class => ['space', 'unit.color', 'plan']])])
            ->when($this->collectorId(), fn ($query, $userId) => $query->collectedBy($userId))
            ->when($openShift, fn ($query) => $query->collectedBy(Auth::id())->where('shift_closing_id', 0));
        if ($id) {
            $query->whereKey($id);
        }

        $table = DataTables::eloquent($query)
            // inside filter() so "of N total" counts all the payments
            ->filter(fn ($query) => $openShift
                ? $query->filter(['search' => trim((string) ($filters['search'] ?? ''))])
                : $query->filter($this->listFilters($filters))->tab($tab))
            ->setRowId(fn ($payment) => 'row-' . $payment->id)
            ->orderColumn('paid_at', fn ($query, $order) => $query->orderBy('paid_at', $order)->orderBy('payment_no', $order))
            ->addColumn('member_html', fn ($payment) => view('paymentmodule::Payment.partials.row-member', compact('payment'))->render())
            // the date + the time it was added (paid_at has no time) + "Late entry"
            ->editColumn('paid_at', fn ($payment) => view('paymentmodule::Payment.partials.record-date', ['record' => $payment])->render())
            ->addColumn('source', fn ($payment) => view('paymentmodule::Payment.partials.row-source', compact('payment'))->render())
            ->addColumn('method', fn ($payment) => e($payment->paymentMethod?->name ?? '-'))
            ->editColumn('amount', fn ($payment) => view('paymentmodule::Payment.partials.row-amount', compact('payment'))->render())
            ->addColumn('by', fn ($payment) => e($payment->creatable?->userable?->displayName() ?? '-'))
            ->addColumn('actions', fn ($payment) => view('paymentmodule::Payment.partials.row-actions', compact('payment'))->render())
            ->rawColumns(['member_html', 'paid_at', 'source', 'amount', 'actions'])
            ->only(['DT_RowId', 'member_html', 'paid_at', 'source', 'method', 'amount', 'by', 'actions']);

        if ($id || $openShift) {
            return $table;
        }

        // the summary line above the table, for the same rows (datatables.js json "html" → [data-html])
        $summaryQuery = $this->paymentService->listQuery($this->accountId(), $this->listFilters($filters))->tab($tab)
            ->when($this->collectorId(), fn ($query, $userId) => $query->collectedBy($userId));
        $summary = $this->paymentService->listSummary($summaryQuery);

        return $table->with('html', ['payments-summary' => view('paymentmodule::Payment.partials.list-summary', compact('summary'))->render()]);
    }

    /**
     * The All / Cash Out tabs (DataTables server side, direction=all | out): payments and expenses together, newest first
     * (CashListService, one UNION query; their models loaded in two queries). Columns: Date (time) | Type | Member or Category |
     * For / Item | Method | Amount (in green +, out red −) | By (account only) | row menu (the payment's / expense's own).
     * Same rules as the lists: tabs Today | This Month | All + one date field; employees: their own rows, ONE day, no tabs.
     */
    public function cashData(Request $request)
    {
        $input = ['search' => $request->input('search.value')] + $request->all();
        $direction = ($input['direction'] ?? '') === 'out' ? 'out' : 'all';
        $tab = $this->tab($input);
        $filters = $this->listFilters($input) + Arr::only($input, $direction === 'out' ? ['category'] : []);
        $accountId = $this->accountId();

        $query = $this->cashListService->query($accountId, $direction, $filters, $tab, $this->collectorId());
        $total = $this->cashListService->query($accountId, $direction, [], 'all', $this->collectorId())->count();
        $filtered = (clone $query)->count();

        $order = $request->input('order.0', []);
        $column = $request->input('columns.' . ($order['column'] ?? '') . '.name', '');
        $rows = $this->cashListService->order(clone $query, (string) $column, (string) ($order['dir'] ?? 'desc'))
            ->offset(max(0, (int) $request->input('start', 0)))->limit(min(500, max(1, (int) $request->input('length', 25))))->get();

        $data = array_map(fn ($item) => $this->cashRow($item['kind'], $item['model']), $this->cashListService->load($rows));
        $summary = $this->cashListService->summary($query);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
            'html' => ['cash-summary' => view('paymentmodule::Payment.partials.cash-summary', compact('summary', 'direction'))->render()],
        ]);
    }

    // one row of the All / Cash Out list (the cells: Payment/partials/cash-cell)
    private function cashRow(string $kind, $model): array
    {
        $cell = fn ($name) => view('paymentmodule::Payment.partials.cash-cell', ['cell' => $name, 'kind' => $kind, 'item' => $model])->render();

        return [
            'DT_RowId' => 'row-' . $kind . '-' . $model->id,
            'at_date' => view('paymentmodule::Payment.partials.record-date', ['record' => $model])->render(),
            'type' => $cell('type'),
            'party' => $cell('party'),
            'what' => $cell('what'),
            'method' => e($kind === 'payment' ? ($model->paymentMethod?->name ?? '-') : $model->method_name),
            'amount' => $cell('amount'),
            'by' => e($model->creatable?->userable?->displayName() ?? '-'),
            'actions' => $kind === 'payment'
                ? view('paymentmodule::Payment.partials.row-actions', ['payment' => $model])->render()
                : view('paymentmodule::Expense.partials.row-actions', ['expense' => $model])->render(),
        ];
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more (or deleted)
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    // the member's reservations / packages (json [{id, name}]) for the form: ?member_id=&payable=reservation|package
    public function payables(Request $request)
    {
        $kind = (string) $request->input('payable');
        if (!isset(PaymentService::PAYABLES[$kind])) {
            return response()->json([]);
        }

        return response()->json($this->paymentService->payableOptions($this->accountId(), $kind, (int) $request->input('member_id')));
    }

    // ?payable=reservation|package&payable_id=X: the form opens for it (the "Add payment" of a reservation / package view)
    public function create(Request $request)
    {
        $accountId = $this->accountId();
        $payment = new Payment(['type' => 'payment', 'paid_at' => today()]);
        // reservation | package | '' (other income)
        $payableKind = 'reservation';
        $payableOptions = [];
        $payable = null;

        if (isset(PaymentService::PAYABLES[$request->input('payable')]) && $request->filled('payable_id')) {
            $payableKind = $request->input('payable');
            $payable = $this->paymentService->findPayable($accountId, $payableKind, (int) $request->input('payable_id'));
            $payment->payable_id = $payable->getKey();
            $payment->member_id = $payable->payableMemberId();
            // the amount still to pay (nothing when it is paid / a credit)
            $payment->amount = max(0, (float) $payable->remaining) ?: null;
            $payableOptions = $this->paymentService->payableOptions($accountId, $payableKind, $payment->member_id);
        }

        return view('paymentmodule::Payment.create', [
            'payment' => $payment,
            'payableKind' => $payableKind,
            // opened for one reservation / package (its view, a list row menu): shown as details, not chosen (hidden inputs)
            'payable' => $payable,
            'payableOptions' => collect($payableOptions)->pluck('name', 'id')->all(),
            // For = Package: every package of the account (name · period · member — Remaining x), owed first
            'packageOptions' => $payable ? [] : collect($this->paymentService->packageOptions($accountId))->pluck('name', 'id')->all(),
            // the Member field searches the server (member-select); its quick add offers the account's companies
            'companies' => $this->companyService->options($accountId),
            'methods' => $this->methodService->options($accountId),
            'categories' => $this->categoryService->options($accountId),
        ]);
    }

    public function store(PaymentRequest $request)
    {
        $payment = $this->paymentService->create($this->accountId(), $request->validated(), Auth::user());
        // a reservation / package payment: back to its view (popup or full page), with the new payment in its block
        $payableUrl = $request->validated('payable') ? route($this->area() . '.' . $request->validated('payable') . 's.show', $payment->payable_id) : null;
        // a missed payment added from "My Shift": that page again (its open shift with the new payment)
        if ($request->boolean('back_to_shift')) {
            return response()->json([
                'message' => 'Cash In #' . $payment->payment_no . ' has been added to your shift.',
                'redirect' => route($this->area() . '.shift.index'),
            ]);
        }

        return response()->json([
            'message' => 'Cash In #' . $payment->payment_no . ' has been added successfully.',
            'reload' => true,
            'modal' => $request->boolean('back_to_payable') ? $payableUrl : null,
            'redirect' => $payableUrl ?? $this->route('show', $payment->id),
        ]);
    }

    public function show($id)
    {
        $payment = $this->findForViewer($id);
        $payableName = $this->paymentService->payableName($payment->payable, false);
        return view('paymentmodule::Payment.show', compact('payment', 'payableName'));
    }

    // the receipt (PDF, opened in the browser)
    public function receipt($id)
    {
        $payment = $this->findForViewer($id);
        $payableName = $this->paymentService->payableName($payment->payable, false);
        $account = $payment->account;

        // the Cairo font of config/pdf.php when its files are there, else mpdf's DejaVu Sans (Arabic too)
        $config = is_file(config('pdf.font_path') . 'Cairo-Regular.ttf') ? [] : ['default_font' => 'dejavusans'];
        $config += ['format' => 'A5',
            // the Arabic title / names: shaped and right to left (like the package statement)
            'instanceConfigurator' => function ($mpdf) {
                $mpdf->autoScriptToLang = true;
                $mpdf->autoLangToFont = true;
            },
        ];

        // a normal response (the package's stream() echoes it itself, outside the Laravel response)
        $pdf = Pdf::loadView('paymentmodule::Payment.receipt-pdf', compact('payment', 'payableName', 'account'), [], $config)->output();
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="cash-in-receipt-' . $payment->payment_no . '.pdf"',
        ]);
    }

    // the refund popup (account + employees; an employee: a payment they can open): reason required, not more than this payment less its refunds
    public function refundForm($id)
    {
        $payment = $this->findForViewer($id);
        return view('paymentmodule::Payment.refund', [
            'payment' => $payment,
            'payableName' => $this->paymentService->payableName($payment->payable, false),
            'refundable' => $this->paymentService->refundable($payment),
            'closedShift' => $this->paymentService->closedShiftOf($payment),
            'methods' => $this->methodService->options($this->accountId()),
        ]);
    }

    public function refund(PaymentRefundRequest $request, $id)
    {
        $payment = $this->findForViewer($id);
        $refund = $this->paymentService->refund($this->accountId(), $payment->id, $request->validated(), Auth::user());

        return response()->json([
            'message' => 'Refund #' . $refund->payment_no . ' has been added to your shift.',
            'reload' => true,
            'modal' => $this->route('show', $refund->id),
            'redirect' => $this->route('show', $refund->id),
        ]);
    }
}
