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
        return Role::withCount('employees')->where('account_id', $accountId);
    }
}
