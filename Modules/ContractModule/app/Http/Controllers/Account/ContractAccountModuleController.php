<?php

namespace Modules\ContractModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\ContractModule\app\Http\Controllers\Concerns\ContractActions;

// logged in account: the contracts (ContractActions)
class ContractAccountModuleController extends Controller
{
    use ContractActions;

    protected function area(): string
    {
        return 'account';
    }
}
