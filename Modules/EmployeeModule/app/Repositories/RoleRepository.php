<?php

namespace Modules\EmployeeModule\app\Repositories;

use Modules\EmployeeModule\app\Models\Role;
use Prettus\Repository\Eloquent\BaseRepository;

class RoleRepository extends BaseRepository
{
    public function model()
    {
        return Role::class;
    }

    // roles of one account only (+ how many employees, not deleted, have each)
    public function forAccount($accountId)
    {
        // employees_count = the LOGINS with the role (the Roles "Users" column + the delete check); HR files without a login don't count
        return Role::withCount(['employees' => fn ($query) => $query->withLogin()])->where('account_id', $accountId);
    }
}
