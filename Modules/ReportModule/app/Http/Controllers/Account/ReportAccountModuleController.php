<?php

namespace Modules\ReportModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\ReportModule\app\Http\Controllers\Concerns\ReportActions;

// the reports (docs/reports-plan.md) for the logged in account (ReportActions)
class ReportAccountModuleController extends Controller
{
    use ReportActions;

    protected function area(): string
    {
        return 'account';
    }
}
