<?php

namespace Modules\PayrollModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\PayrollModule\app\Http\Controllers\Concerns\SalaryActions;

// an employee's (hr.salary) Salary tab popups (Concerns\SalaryActions)
class SalaryEmployeeModuleController extends Controller
{
    use SalaryActions;

    protected function area(): string
    {
        return 'employee';
    }
}
