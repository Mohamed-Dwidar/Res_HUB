<?php

namespace Modules\HrModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\HrModule\app\Http\Controllers\Concerns\LeaveActions;

// the Leaves tab popups (Concerns\LeaveActions)
class LeaveAccountModuleController extends Controller
{
    use LeaveActions;

    protected function area(): string
    {
        return 'account';
    }
}
