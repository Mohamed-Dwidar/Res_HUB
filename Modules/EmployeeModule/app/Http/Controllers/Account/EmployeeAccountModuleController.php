<?php

namespace Modules\EmployeeModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\EmployeeModule\app\Models\Employee;

/**
 * The old Users (employee logins) pages — REMOVED 2026-11-04 (user, docs/hr-plan.md §A): HR › Staff is the one place for
 * people; a login is made / changed / switched off from the staff form's "System Access" section.
 * The old GET urls redirect: an employee of the account → its staff page (the same id since 2026-11-05: the employees row is
 * the HR file), else the Staff list (no 404 for old links / bookmarks).
 */
class EmployeeAccountModuleController extends Controller
{
    public function redirectToStaff($id = null)
    {
        $exists = $id && Employee::where('account_id', Auth::user()->userable_id)->whereKey((int) $id)->exists();
        return redirect($exists ? route('account.hr.staff.show', (int) $id) : route('account.hr.staff.index'));
    }
}
