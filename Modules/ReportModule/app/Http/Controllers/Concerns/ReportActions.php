<?php

namespace Modules\ReportModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\MemberModule\app\Models\Member;
use Modules\MemberModule\app\Services\MemberService;
use Modules\PaymentModule\app\Services\PaymentMethodService;
use Modules\ReportModule\app\Exports\IncomeExport;
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
            ->editColumn('cash_in', $cell('cash_in', 'money-in'))
            ->editColumn('refunds', $cell('refunds', 'money-out'))
            ->editColumn('withholding', $cell('withholding', 'money-withholding'))
            ->editColumn('tax', $cell('tax'))
            ->editColumn('net', $cell('net'))
            ->editColumn('total', $cell('total', 'font-medium'))
            ->rawColumns(['cash_in', 'refunds', 'withholding', 'tax', 'net', 'total'])
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

    public function incomeExcel(Request $request, IncomeReport $report)
    {
        $filters = $report->filters($request->all());
        $totals = $report->totals($this->accountId(), $filters);
        $this->logExport('Report: Income', $filters, (int) $totals->groups_count);

        return Excel::download(new IncomeExport($report->query($this->accountId(), $filters), $totals, IncomeReport::GROUPS[$filters['group']], $report->rangeText($filters)),
            'income-' . now()->format('Y-m-d') . '.xlsx');
    }
}
