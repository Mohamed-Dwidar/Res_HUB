<?php

namespace Modules\PayrollModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\PayrollModule\app\Http\Controllers\Concerns\AdvanceActions;

// the Advances & Adjustments tab popups (Concerns\AdvanceActions)
class AdvanceAccountModuleController extends Controller
{
    use AdvanceActions;

    protected function area(): string
    {
        return 'account';
    }
}
