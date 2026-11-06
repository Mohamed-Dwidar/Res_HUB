<?php

namespace Modules\ReportModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\ReportModule\app\Http\Controllers\Concerns\ReportActions;

// the reports (docs/reports-plan.md) for the logged in employee (ReportActions)
class ReportEmployeeModuleController extends Controller
{
    use ReportActions;

    protected function area(): string
    {
        return 'employee';
    }
}
