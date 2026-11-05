<?php

namespace Modules\PayrollModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\PayrollModule\app\Http\Controllers\Concerns\PayrollActions;

// HR › Payroll (Concerns\PayrollActions)
class PayrollEmployeeModuleController extends Controller
{
    use PayrollActions;

    protected function area(): string
    {
        return 'employee';
    }
}
