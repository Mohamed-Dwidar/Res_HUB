<?php

namespace Modules\ReportModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\MemberModule\app\Models\Member;
use Modules\MemberModule\app\Services\MemberService;
use Modules\PaymentModule\app\Services\PaymentMethodService;
use Illuminate\Support\Facades\Gate;
use Modules\PaymentModule\app\Services\CashScope;
use Modules\PaymentModule\app\Services\ExpenseCategoryService;
use Modules\ReportModule\app\Exports\ExpenseExport;
use Modules\ReportModule\app\Exports\IncomeExport;
use Modules\ReportModule\app\Services\ExpenseReport;
use Modules\ReportModule\app\Services\SubscriptionReport;
use Modules\ReportModule\app\Services\OccupancyReport;
use Modules\ReportModule\app\Exports\OccupancyExport;
use Modules\ReportModule\app\Exports\PnlExport;
use Modules\ReportModule\app\Services\PnlReport;
use Modules\ReportModule\app\Services\MemberReport;
use Modules\ReportModule\app\Exports\MemberExport;
use Modules\ReportModule\app\Exports\SubscriptionExport;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Services\RenewalView;
use Modules\ReportModule\app\Exports\MemberStatementExport;
use Modules\ReportModule\app\Services\IncomeReport;
use Modules\ReportModule\app\Services\ReportDates;
use Modules\ReportModule\app\Exports\OutstandingExport;
use Modules\ReportModule\app\Services\MemberStatementReport;
use niklasravnsborg\LaravelPdf\Facades\Pdf;
use Modules\ReportModule\app\Services\OutstandingReport;
use Modules\SpaceModule\app\Services\SpaceService;
use Modules\UnitModule\app\Services\UnitService;
use Modules\SpaceModule\app\Services\SubscriptionTypeService;
use Yajra\DataTables\Facades\DataTables;

/**
 * Reports (docs/reports-plan.md), shared by the account and its employees (route `can:reports.*`; the owner passes all).
 * A report = the whole account's data. The controller gives area(): "account" | "employee" (route names {area}.reports.*).
 * Every export is written in the Access Log (the owner's too).
 */
trait ReportActions
{
    abstract protected function area(): string;

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // the toolbar's type → space → unit / plan options (all, active first; inactive greyed); plans carry their spaces (data-match)
    private function placeOptions(): array
    {
        $accountId = $this->accountId();
        $planSpaces = DB::table('plan_space')->join('plans', 'plans.id', '=', 'plan_space.plan_id')
            ->where('plans.account_id', $accountId)->get(['plan_space.plan_id', 'plan_space.space_id'])
            ->groupBy('plan_id')->map(fn ($rows) => $rows->pluck('space_id')->all());
        $plans = DB::table('plans')->where('account_id', $accountId)->whereNull('deleted_at')
            ->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn ($plan) => [$plan->id => ['name' => $plan->name, 'active' => (bool) $plan->is_active,
                'spaces' => $planSpaces[$plan->id] ?? []]])->all();

        return [
            'types' => app(SubscriptionTypeService::class)->options($accountId),
            'spaces' => app(SpaceService::class)->filterOptions($accountId),
            // the Unit filter (Income): shown once a space is chosen, its units only (custom.js filterChain, data-match)
            'units' => app(UnitService::class)->filterOptions($accountId),
            'plans' => $plans,
        ];
    }

    // a report row may open the Cash Out / payment popup only when the login could open it there (route can: + CashScope: every
    // row or its own; a payment of a reservation / package opens for any employee with payments.view — PaymentActions::findForViewer())
    private function canOpenExpense($expense): bool
    {
        return Gate::allows('expenses.view')
            && (app(CashScope::class)->expensesCollector() === null || (int) $expense->creatable_id === (int) Auth::id());
    }

    private function canOpenPayment($payment): bool
    {
        return Gate::allows('payments.view')
            && (app(CashScope::class)->paymentsCollector() === null || $payment->payable_type || (int) $payment->creatable_id === (int) Auth::id());
    }

    private function logExport(string $page, array $filters, int $rows, string $format = 'xlsx'): void
    {
        app(AccessLogService::class)->record('export', Auth::user(),
            ['page' => $page, 'format' => $format, 'filters' => array_filter($filters), 'rows' => $rows], true);
    }

    // ---- 1. Outstanding & credits ----

    public function outstanding()
    {
        return view('reportmodule::Report.outstanding', $this->placeOptions() + ['area' => $this->area()]);
    }

    // DataTables server side: one row per member + the totals (json html)
    public function outstandingData(Request $request, OutstandingReport $report)
    {
        $filters = $report->filters(['search' => $request->input('search.value')] + $request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $area = $this->area();

        return DataTables::query($report->query($this->accountId(), $filters))
            ->filter(fn () => null)
            ->setRowId(fn ($row) => 'row-' . $row->member_id)
            ->addColumn('member_html', fn ($row) => view('reportmodule::Report.partials.outstanding-member', ['row' => $row, 'area' => $area, 'filters' => $filters])->render())
            ->editColumn('owed', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->owed, 'class' => 'money-out'])->render())
            ->editColumn('credit', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->credit, 'class' => 'money-withholding'])->render())
            ->editColumn('net', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->net, 'class' => $row->net < 0 ? 'money-withholding' : 'font-medium'])->render())
            ->editColumn('oldest', fn ($row) => view('reportmodule::Report.partials.due-since', ['date' => $row->oldest])->render())
            ->rawColumns(['member_html', 'owed', 'credit', 'net', 'oldest'])
            ->only(['DT_RowId', 'member_html', 'owed', 'credit', 'net', 'periods', 'oldest'])
            ->with('html', [
                'outstanding-totals' => view('reportmodule::Report.partials.outstanding-totals', compact('totals'))->render(),
                'outstanding-foot-members' => e($totals->members . ' members'),
                'outstanding-foot-owed' => number_format($totals->owed, 2),
                'outstanding-foot-credit' => number_format($totals->credit, 2),
                'outstanding-foot-net' => number_format($totals->net, 2),
                'outstanding-foot-periods' => (int) $totals->periods,
            ])
            ->toJson();
    }

    // the popup: one member's rows ($member 0 = the rows without a member)
    public function outstandingMember(Request $request, OutstandingReport $report, $member)
    {
        $filters = $report->filters($request->all());
        $memberId = (int) $member;
        $model = $memberId ? Member::withTrashed()->where('account_id', $this->accountId())->findOrFail($memberId) : null;

        return view('reportmodule::Report.outstanding-member', $report->memberRows($this->accountId(), $memberId, $filters)
            + ['member' => $model, 'area' => $this->area()]);
    }

    public function outstandingExcel(Request $request, OutstandingReport $report)
    {
        $filters = $report->filters($request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $this->logExport('Report: Outstanding & credits', $filters, (int) $totals->members);

        return Excel::download(new OutstandingExport($report->query($this->accountId(), $filters), $totals),
            'outstanding-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 2. Member statement ----

    // the page (?member_id= = that member chosen, e.g. from the Outstanding popup)
    public function memberStatement(Request $request, MemberStatementReport $report)
    {
        $member = $report->member($this->accountId(), (int) $request->query('member_id'));
        return view('reportmodule::Report.member-statement', ['member' => $member, 'area' => $this->area()]);
    }

    // the statement as html (json html: the page puts it under the toolbar)
    public function memberStatementData(Request $request, MemberStatementReport $report)
    {
        $statement = $report->statement($this->accountId(), $report->filters($request->all()));
        return response()->json(['html' => view('reportmodule::Report.partials.member-statement-body', compact('statement'))->render()]);
    }

    // the member search of the toolbar (the forms' search rule: name / mobile, 20)
    public function memberStatementMembers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        return response()->json(mb_strlen($q) < 2 ? [] : app(MemberService::class)->search($this->accountId(), $q));
    }

    public function memberStatementPdf(Request $request, MemberStatementReport $report)
    {
        $filters = $report->filters($request->all());
        $statement = $report->statement($this->accountId(), $filters);
        abort_unless($statement, 404);
        $this->logExport('Report: Member statement', $filters + ['member' => $statement['member']->name], count($statement['lines']), 'pdf');

        $config = [
            'format' => 'A4',
            'margin_top' => 12, 'margin_bottom' => 18, 'margin_footer' => 6,
            'instanceConfigurator' => function ($mpdf) {
                $mpdf->autoScriptToLang = true;
                $mpdf->autoLangToFont = true;
            },
        ];
        if (!is_file(config('pdf.font_path') . 'Cairo-Regular.ttf')) {
            $config['default_font'] = 'dejavusans';
        }
        $pdf = Pdf::loadView('reportmodule::Report.member-statement-pdf', $statement + ['account' => \Modules\AccountModule\app\Models\Account::find($this->accountId())], [], $config)->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="member-statement-' . $statement['member']->id . '.pdf"',
        ]);
    }

    public function memberStatementExcel(Request $request, MemberStatementReport $report)
    {
        $filters = $report->filters($request->all());
        $statement = $report->statement($this->accountId(), $filters);
        abort_unless($statement, 404);
        $this->logExport('Report: Member statement', $filters + ['member' => $statement['member']->name], count($statement['lines']));

        return Excel::download(new MemberStatementExport($statement), 'member-statement-' . $statement['member']->id . '-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 3. Income ----

    public function income()
    {
        return view('reportmodule::Report.income', $this->placeOptions() + [
            'area' => $this->area(),
            'initial' => ReportDates::thisMonth(),
            'methods' => app(PaymentMethodService::class)->filterOptions($this->accountId()),
        ]);
    }

    // DataTables server side: one row per group + the totals (json html: box, footer cells, the group column's title)
    public function incomeData(Request $request, IncomeReport $report)
    {
        $filters = $report->filters($request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $cell = fn ($field, $class = null) => fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->$field, 'class' => $class])->render();
        $query = $report->query($this->accountId(), $filters);
        // no column clicked = the group's own order (spaces / units, Packages, other income; methods; months)
        if (!$request->input('order')) {
            $query->orderBy('group_sort');
        }

        return DataTables::query($query)
            ->filter(fn () => null)
            ->setRowId(fn ($row) => 'row-' . md5($row->group_key))
            // a click = the popup of this row's payments (the same filters + group)
            ->setRowAttr(['data-open-url' => fn ($row) => route($this->area() . '.reports.income.group',
                ['key' => $row->group_key] + array_filter($filters))])
            ->setRowClass('report-click')
            ->editColumn('cash_in', $cell('cash_in', 'money-in'))
            ->editColumn('refunds', $cell('refunds', 'money-out'))
            ->editColumn('withholding', $cell('withholding', 'money-withholding'))
            ->editColumn('tax', $cell('tax'))
            ->editColumn('net', $cell('net'))
            ->editColumn('total', $cell('total', 'font-medium'))
            ->rawColumns(['cash_in', 'refunds', 'withholding', 'tax', 'net', 'total', 'DT_RowAttr.data-open-url'])
            ->only(['DT_RowId', 'group_label', 'payments', 'cash_in', 'refunds', 'withholding', 'tax', 'net', 'total'])
            ->with('html', [
                'income-totals' => view('reportmodule::Report.partials.income-totals', ['totals' => $totals])->render(),
                'income-group-title' => e(IncomeReport::GROUPS[$filters['group']]),
                'income-foot-label' => e('Total (' . (int) $totals->groups_count . ' rows)'),
                'income-foot-payments' => (int) $totals->payments,
                'income-foot-cash_in' => number_format($totals->cash_in, 2),
                'income-foot-refunds' => number_format($totals->refunds, 2),
                'income-foot-withholding' => number_format($totals->withholding, 2),
                'income-foot-tax' => number_format($totals->tax, 2),
                'income-foot-net' => number_format($totals->net, 2),
                'income-foot-total' => number_format($totals->total, 2),
            ])
            ->toJson();
    }

    // the popup of one row: its payments (newest first, at most IncomeReport::GROUP_LIMIT) + the row's totals
    public function incomeGroup(Request $request, IncomeReport $report)
    {
        $filters = $report->filters($request->all());
        $data = $report->groupPayments($this->accountId(), $filters, (string) $request->query('key', ''));

        return view('reportmodule::Report.income-group', $data + [
            'area' => $this->area(),
            'group' => IncomeReport::GROUPS[$filters['group']],
            'range' => $report->rangeText($filters),
            'canOpen' => fn ($payment) => $this->canOpenPayment($payment),
            'limit' => IncomeReport::GROUP_LIMIT,
        ]);
    }

    public function incomeExcel(Request $request, IncomeReport $report)
    {
        $filters = $report->filters($request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $this->logExport('Report: Income', $filters, (int) $totals->groups_count);

        return Excel::download(new IncomeExport($report->query($this->accountId(), $filters), $totals, IncomeReport::GROUPS[$filters['group']], $report->rangeText($filters)),
            'income-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 4. Expenses ----

    public function expenses()
    {
        return view('reportmodule::Report.expenses', [
            'area' => $this->area(),
            'initial' => ReportDates::thisMonth(),
            'categories' => app(ExpenseCategoryService::class)->filterOptions($this->accountId()),
            'methods' => app(PaymentMethodService::class)->filterOptions($this->accountId()),
        ]);
    }

    private function expenseFilters(Request $request, ExpenseReport $report): array
    {
        return $report->filters(['search' => $request->input('search.value', $request->input('search'))] + $request->except('search'));
    }

    // By category (DataTables): one row per block + category; the totals (json html) come with this table
    public function expensesCategories(Request $request, ExpenseReport $report)
    {
        $filters = $this->expenseFilters($request, $report);
        $totals = $report->totals($this->accountId(), $filters);
        $query = $report->byCategory($this->accountId(), $filters);
        if (!$request->input('order')) {
            $query->orderBy('is_company')->orderBy('category');
        }

        return DataTables::query($query)
            ->filter(fn () => null)
            ->setRowId(fn ($row) => 'row-' . $row->is_company . '-' . $row->expense_category_id)
            // a click = Details of this block + category (custom.js "Report drill-down")
            ->setRowAttr(['data-drill-category' => fn ($row) => (int) $row->expense_category_id,
                'data-drill-kind' => fn ($row) => $row->is_company ? 'company' : 'shift',
                'data-drill-label' => fn ($row) => ($row->is_company ? 'Company' : 'Shift') . ' · ' . $row->category])
            ->setRowClass('report-click')
            ->editColumn('block', fn ($row) => view('reportmodule::Report.partials.expense-block', ['company' => (bool) $row->is_company])->render())
            ->editColumn('amount', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->amount, 'class' => 'money-out'])->render())
            ->rawColumns(['block', 'amount', 'DT_RowAttr.data-drill-label'])
            ->only(['DT_RowId', 'block', 'category', 'count', 'amount'])
            ->with('html', [
                'expense-totals' => view('reportmodule::Report.partials.expense-totals', ['totals' => $totals])->render(),
                'expense-foot-label' => e('Total (' . (int) $totals->categories . ' rows)'),
                'expense-foot-count' => (int) $totals->count,
                'expense-foot-amount' => number_format($totals->total, 2),
            ])
            ->toJson();
    }

    // Details (DataTables): every Cash Out, newest first
    public function expensesDetails(Request $request, ExpenseReport $report)
    {
        $filters = $this->expenseFilters($request, $report);
        $query = $report->details($this->accountId(), $filters);
        if (!$request->input('order')) {
            $query->orderByDesc('expenses.spent_at')->orderByDesc('expenses.id');
        }
        $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
        $area = $this->area();

        return DataTables::eloquent($query)
            ->filter(fn () => null)
            ->setRowId(fn ($expense) => 'row-' . $expense->id)
            // a click = the Cash Out popup (when the login may open it)
            ->setRowAttr(['data-open-url' => fn ($expense) => $this->canOpenExpense($expense) ? route($area . '.expenses.show', $expense->id) : null])
            ->setRowClass(fn ($expense) => $this->canOpenExpense($expense) ? 'report-click' : '')
            ->editColumn('spent_at', fn ($expense) => $expense->spent_at?->format($dateFormat))
            ->addColumn('category', fn ($expense) => view('reportmodule::Report.partials.expense-category', ['expense' => $expense])->render())
            ->addColumn('method', fn ($expense) => e($expense->method_name))
            ->addColumn('by', fn ($expense) => e($expense->creatable?->userable?->name ?? '-'))
            ->editColumn('item', fn ($expense) => e($expense->item))
            ->editColumn('amount', fn ($expense) => view('reportmodule::Report.partials.money', ['value' => $expense->amount, 'class' => 'money-out'])->render())
            ->rawColumns(['category', 'amount', 'DT_RowAttr.data-open-url'])
            ->only(['DT_RowId', 'expense_no', 'spent_at', 'category', 'item', 'method', 'by', 'amount'])
            ->toJson();
    }

    public function expensesExcel(Request $request, ExpenseReport $report)
    {
        $filters = $report->filters($request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $this->logExport('Report: Expenses', $filters, (int) $totals->count);

        return Excel::download(new ExpenseExport($report, $this->accountId(), $filters, $totals), 'expenses-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 5. Subscriptions ----

    public function subscriptions()
    {
        return view('reportmodule::Report.subscriptions', $this->placeOptions() + [
            'area' => $this->area(),
            'initial' => ReportDates::thisMonth(),
            'days' => Reservation::renewalDaysOf(),
        ]);
    }

    private function subscriptionFilters(Request $request, SubscriptionReport $report): array
    {
        return $report->filters(['search' => $request->input('search.value', $request->input('search'))] + $request->except('search'));
    }

    // DataTables server side: the chosen tab's rows; json counts = every tab's count, html = the totals box
    public function subscriptionsData(Request $request, SubscriptionReport $report)
    {
        $filters = $this->subscriptionFilters($request, $report);
        $accountId = $this->accountId();
        $counts = $report->counts($accountId, $filters);
        $totals = $report->totals($accountId, $filters);
        $query = $report->list($accountId, $filters);
        if (!$request->input('order')) {
            $filters['view'] === 'new'
                ? $query->orderBy('reservations.start_at')
                : $query->orderByRaw(Reservation::RENEWAL_DATE_SQL)->orderBy('reservations.id');
        }
        [$today] = Reservation::businessToday();
        $kind = SubscriptionReport::kindOf($filters['view']);
        $area = $this->area();
        $canOpen = Gate::allows('reservations.view');
        $format = Reservation::DATE_FORMAT;

        return DataTables::eloquent($query)
            ->filter(fn () => null)
            ->setRowId(fn ($row) => 'row-' . $row->id)
            ->setRowAttr(['data-open-url' => fn ($row) => $canOpen ? route($area . '.reservations.show', $row->id) : null])
            ->setRowClass($canOpen ? 'report-click' : '')
            ->addColumn('member_html', fn ($row) => view('reportmodule::Report.partials.subscription-member', ['row' => $row])->render())
            ->addColumn('place', fn ($row) => view('reservationmodule::Reservation.partials.row-place', ['reservation' => $row])->render())
            ->addColumn('plan_name', fn ($row) => e($row->plan?->name ?? '-'))
            ->addColumn('period', fn ($row) => e($row->start_at?->format($format) . ' → ' . ($row->realEnd()?->format($format) ?? '-')))
            ->addColumn('renewal', fn ($row) => view('reportmodule::Report.partials.subscription-renewal', ['view' => RenewalView::make($row, $kind, $today),
                'relative' => in_array($filters['view'], ['not_renewed', 'ending'], true)])->render())
            ->addColumn('amount', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->dueAmount(), 'class' => 'font-medium'])->render())
            ->addColumn('state', fn ($row) => view('reservationmodule::Reservation.partials.renewal-payment', ['view' => RenewalView::make($row, $kind, $today), 'area' => $area])->render())
            ->rawColumns(['member_html', 'place', 'renewal', 'amount', 'state', 'DT_RowAttr.data-open-url'])
            ->only(['DT_RowId', 'DT_RowAttr', 'DT_RowClass', 'member_html', 'place', 'plan_name', 'period', 'renewal', 'amount', 'state'])
            ->with('counts', collect($counts)->mapWithKeys(fn ($n, $view) => ['subscriptions-' . $view => $n])->all())
            ->with('html', [
                'subscription-totals' => view('reportmodule::Report.partials.subscription-totals', ['totals' => $totals, 'counts' => $counts, 'filters' => $filters, 'range' => $report->rangeText($filters)])->render(),
            ])
            ->toJson();
    }

    public function subscriptionsExcel(Request $request, SubscriptionReport $report)
    {
        $filters = $report->filters($request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $this->logExport('Report: Subscriptions', $filters, (int) $totals->count);
        $query = $report->list($this->accountId(), $filters);
        $filters['view'] === 'new' ? $query->orderBy('reservations.start_at') : $query->orderByRaw(Reservation::RENEWAL_DATE_SQL)->orderBy('reservations.id');

        return Excel::download(new SubscriptionExport($query, $totals, SubscriptionReport::TABS[$filters['view']], $report->rangeText($filters), SubscriptionReport::kindOf($filters['view'])),
            'subscriptions-' . $filters['view'] . '-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 6. Occupancy ----

    public function occupancy()
    {
        $places = $this->placeOptions();
        return view('reportmodule::Report.occupancy', [
            'area' => $this->area(),
            'initial' => ReportDates::thisMonth(),
            // no "All": occupancy needs dates (× = this month)
            'presets' => array_values(array_filter(ReportDates::presets(), fn ($preset) => $preset['from'] !== null)),
            'types' => $places['types'],
            'spaces' => $places['spaces'],
        ]);
    }

    // the report as html (json html: the page puts it under the toolbar; custom.js "Live report")
    public function occupancyData(Request $request, OccupancyReport $report)
    {
        $filters = $report->filters($request->all());
        return response()->json(['html' => view('reportmodule::Report.partials.occupancy-body', $report->data($this->accountId(), $filters)
            + ['range' => $report->rangeText($filters)])->render()]);
    }

    public function occupancyExcel(Request $request, OccupancyReport $report)
    {
        $filters = $report->filters($request->all());
        $data = $report->data($this->accountId(), $filters);
        abort_if($data['error'], 422, $data['error']);
        $this->logExport('Report: Occupancy', $filters, collect($data['spaces'])->sum(fn ($space) => count($space['units'])));

        return Excel::download(new OccupancyExport($data, $report->rangeText($filters)), 'occupancy-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 7. Profit & Loss ----

    public function pnl()
    {
        return view('reportmodule::Report.pnl', ['area' => $this->area(), 'initial' => PnlReport::initial(), 'presets' => PnlReport::presets()]);
    }

    // the report as html (json html; custom.js "Live report")
    public function pnlData(Request $request, PnlReport $report)
    {
        $filters = $report->filters($request->all());
        return response()->json(['html' => view('reportmodule::Report.partials.pnl-body', $report->data($this->accountId(), $filters)
            + ['range' => $report->rangeText($filters), 'dates' => $report->datesText($filters)])->render()]);
    }

    public function pnlExcel(Request $request, PnlReport $report)
    {
        $filters = $report->filters($request->all());
        $data = $report->data($this->accountId(), $filters);
        abort_if($data['error'], 422, $data['error']);
        $this->logExport('Report: Profit & Loss', $filters, count($data['income']) + count($data['expenses']));

        return Excel::download(new PnlExport($data, $report->rangeText($filters)), 'profit-loss-' . now()->format('Y-m-d') . '.xlsx');
    }

    // ---- 8. Members ----

    public function members()
    {
        return view('reportmodule::Report.members', $this->placeOptions() + [
            'area' => $this->area(),
            'initial' => ReportDates::thisMonth(),
            'days' => MemberReport::DEFAULT_DAYS,
            'min' => MemberReport::DEFAULT_MIN,
            'within' => MemberReport::DEFAULT_WITHIN,
        ]);
    }

    private function memberFilters(Request $request, MemberReport $report): array
    {
        return $report->filters(['search' => $request->input('search.value', $request->input('search'))] + $request->except('search'));
    }

    // DataTables server side: the chosen member tab's rows; json counts = every tab's count, html = the totals box
    public function membersData(Request $request, MemberReport $report)
    {
        $filters = $this->memberFilters($request, $report);
        $accountId = $this->accountId();
        $view = $filters['view'] === 'occupation' ? 'new' : $filters['view'];
        $query = $report->tab($accountId, $filters, $view);
        if (!$request->input('order')) {
            $report->order($query, $view);
        }
        $area = $this->area();
        // a row opens the member statement (a new tab) when the login may open it
        $canOpen = Gate::allows('reports.member_statement');
        $format = Reservation::DATE_FORMAT;
        $date = fn ($value) => $value ? Carbon::parse($value)->format($format) : '-';
        $messaging = app(\Modules\MessagingModule\app\Services\MessagingService::class);

        return DataTables::query($query)
            ->filter(fn () => null)
            ->setRowId(fn ($row) => 'row-' . $row->member_id)
            ->setRowAttr(['data-open-page' => fn ($row) => $canOpen ? route($area . '.reports.member-statement', ['member_id' => $row->member_id]) : null])
            ->setRowClass($canOpen ? 'report-click' : '')
            ->editColumn('name', fn ($row) => view('reportmodule::Report.partials.member-name', ['row' => $row])->render())
            // the phone opens WhatsApp (wa.me; the WhatsApp popups' number rule), else plain text
            ->editColumn('phone', function ($row) use ($messaging) {
                [$number] = $row->phone ? $messaging->phone((new Member)->forceFill(['phone' => $row->phone])) : [null];
                return $number ? '<a href="https://wa.me/' . $number . '" target="_blank" rel="noopener" class="member-wa-link">' . e($row->phone) . '</a>'
                    : e($row->phone ?: '-');
            })
            ->editColumn('first_visit', fn ($row) => $date($row->first_visit))
            ->editColumn('last_visit', fn ($row) => $date($row->last_visit))
            ->editColumn('paid', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->paid, 'class' => 'money-in'])->render())
            ->editColumn('balance', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->balance, 'class' => $row->balance < 0 ? 'money-withholding' : 'money-out'])->render())
            ->rawColumns(['name', 'phone', 'paid', 'balance', 'DT_RowAttr.data-open-page'])
            ->only(['DT_RowId', 'DT_RowAttr', 'DT_RowClass', 'name', 'phone', 'first_visit', 'last_visit', 'reservations', 'paid', 'balance'])
            ->with('counts', collect($report->counts($accountId, $filters))->mapWithKeys(fn ($n, $tab) => ['members-' . $tab => $n])->all())
            ->with('html', [
                'member-totals' => view('reportmodule::Report.partials.member-totals', ['totals' => $report->totals($accountId, $filters),
                    'filters' => $filters, 'range' => $report->rangeText($filters)])->render(),
            ])
            ->toJson();
    }

    // DataTables server side: By occupation (one row per occupation)
    public function membersOccupations(Request $request, MemberReport $report)
    {
        $filters = $this->memberFilters($request, $report);
        $query = $report->occupations($this->accountId(), $filters);
        if (!$request->input('order')) {
            $query->orderByDesc('paid')->orderByDesc('members');
        }

        return DataTables::query(DB::query()->fromSub($query, 'x'))
            ->filter(fn () => null)
            ->setRowId(fn ($row) => 'row-job-' . (int) $row->job_id)
            ->editColumn('paid', fn ($row) => view('reportmodule::Report.partials.money', ['value' => $row->paid, 'class' => 'money-in'])->render())
            ->rawColumns(['paid'])
            ->only(['DT_RowId', 'occupation', 'members', 'new_members', 'paid'])
            ->toJson();
    }

    public function membersExcel(Request $request, MemberReport $report)
    {
        $filters = $report->filters($request->all());
        $accountId = $this->accountId();
        $totals = $report->totals($accountId, $filters);
        $this->logExport('Report: Members', $filters, (int) $totals->rows_count);
        if ($filters['view'] === 'occupation') {
            $query = $report->occupations($accountId, $filters)->orderByDesc('paid')->orderByDesc('members');
        } else {
            $query = $report->order($report->tab($accountId, $filters), $filters['view']);
        }

        return Excel::download(new MemberExport($query, $totals, $filters['view'], $report->rangeText($filters)),
            'members-' . $filters['view'] . '-' . now()->format('Y-m-d') . '.xlsx');
    }
}
