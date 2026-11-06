<?php

namespace Modules\ReportModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\MemberModule\app\Models\Member;
use Modules\ReportModule\app\Exports\OutstandingExport;
use Modules\ReportModule\app\Services\OutstandingReport;
use Modules\SpaceModule\app\Services\SpaceService;
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

    // the toolbar's type → space → plan options (all, active first; inactive greyed); plans carry their spaces (data-match)
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
            'plans' => $plans,
        ];
    }

    private function logExport(string $page, array $filters, int $rows): void
    {
        app(AccessLogService::class)->record('export', Auth::user(),
            ['page' => $page, 'format' => 'xlsx', 'filters' => array_filter($filters), 'rows' => $rows], true);
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
}
