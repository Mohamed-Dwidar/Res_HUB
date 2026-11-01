<?php

namespace Modules\ContractModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\ContractModule\app\Http\Controllers\Concerns\ContractActions;

// logged in employee: the account's contracts (ContractActions) — create / edit drafts and their text, issue, print, upload signed,
// delete their own drafts
class ContractEmployeeModuleController extends Controller
{
    use ContractActions;

    protected function area(): string
    {
        return 'employee';
    }
}
