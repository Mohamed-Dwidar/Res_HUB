<?php

namespace Modules\HrModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\HrModule\app\Http\Controllers\Concerns\StaffActions;

// logged in employee with an HR role (hr.* / documents.*): the account's staff files (StaffActions)
class StaffEmployeeModuleController extends Controller
{
    use StaffActions;

    protected function area(): string
    {
        return 'employee';
    }
}
