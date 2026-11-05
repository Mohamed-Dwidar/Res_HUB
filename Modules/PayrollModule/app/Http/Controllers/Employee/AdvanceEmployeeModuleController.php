<?php

namespace Modules\PayrollModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\PayrollModule\app\Http\Controllers\Concerns\AdvanceActions;

// the Advances & Adjustments tab popups (Concerns\AdvanceActions)
class AdvanceEmployeeModuleController extends Controller
{
    use AdvanceActions;

    protected function area(): string
    {
        return 'employee';
    }
}
