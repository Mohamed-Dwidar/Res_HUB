<?php

namespace Modules\PayrollModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\PayrollModule\app\Http\Controllers\Concerns\SalaryActions;

// the account's Salary tab popups (Concerns\SalaryActions)
class SalaryAccountModuleController extends Controller
{
    use SalaryActions;

    protected function area(): string
    {
        return 'account';
    }
}
