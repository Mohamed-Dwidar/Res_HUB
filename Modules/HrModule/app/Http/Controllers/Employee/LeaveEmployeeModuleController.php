<?php

namespace Modules\HrModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\HrModule\app\Http\Controllers\Concerns\LeaveActions;

// the Leaves tab popups (Concerns\LeaveActions)
class LeaveEmployeeModuleController extends Controller
{
    use LeaveActions;

    protected function area(): string
    {
        return 'employee';
    }
}
