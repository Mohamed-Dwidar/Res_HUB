<?php

namespace Modules\PayrollModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\PayrollModule\app\Http\Controllers\Concerns\PayrollActions;

// HR › Payroll (Concerns\PayrollActions)
class PayrollAccountModuleController extends Controller
{
    use PayrollActions;

    protected function area(): string
    {
        return 'account';
    }
}
