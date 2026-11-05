<?php

namespace Modules\HrModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\HrModule\app\Http\Controllers\Concerns\StaffActions;

// logged in account: the staff files (StaffActions) + delete a file made by mistake (no documents)
class StaffAccountModuleController extends Controller
{
    use StaffActions;

    protected function area(): string
    {
        return 'account';
    }

    public function destroy($id)
    {
        $this->staffService->deleteOne($this->accountId(), $id, Auth::user());

        return response()->json(['message' => 'The staff file has been deleted.', 'row' => null, 'redirect' => route('account.hr.staff.index')]);
    }
}
