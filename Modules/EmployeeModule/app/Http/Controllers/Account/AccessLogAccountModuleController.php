<?php

namespace Modules\EmployeeModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Yajra\DataTables\Facades\DataTables;

// the owner: Management › Access Log (docs/roles-plan.md) — the employees' logins, failed logins, logouts, idle timeouts,
// exports and sensitive actions; read only, newest first
class AccessLogAccountModuleController extends Controller
{
    public function __construct(private AccessLogService $logService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    public function index()
    {
        return view('employeemodule::Account.AccessLog.index', [
            'logins' => $this->logService->loginOptions($this->accountId()),
            'events' => config('employeemodule.access_events'),
        ]);
    }

    // DataTables server side: user_id | event | date_from / date_to
    public function data(Request $request)
    {
        $filters = Arr::only($request->all(), ['user_id', 'event', 'date_from', 'date_to']);

        return DataTables::eloquent($this->logService->listQuery($this->accountId()))
            ->filter(fn ($query) => $query->filter($filters))
            ->setRowId(fn ($log) => 'row-' . $log->id)
            ->editColumn('created_at', fn ($log) => $log->created_at?->format('d-m-Y h:i:s A'))
            ->addColumn('who', fn ($log) => e($log->user?->userable?->displayName() ?? $log->email ?? '-'))
            ->addColumn('event_html', fn ($log) => view('employeemodule::Account.AccessLog.partials.event', compact('log'))->render())
            ->addColumn('details', fn ($log) => view('employeemodule::Account.AccessLog.partials.details', compact('log'))->render())
            ->addColumn('client', fn ($log) => view('employeemodule::Account.AccessLog.partials.client', compact('log'))->render())
            ->rawColumns(['event_html', 'details', 'client'])
            ->only(['DT_RowId', 'created_at', 'who', 'event_html', 'details', 'client'])
            ->toJson();
    }
}
