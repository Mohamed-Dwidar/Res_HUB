<?php

namespace Modules\EmployeeModule\app\Repositories;

use Modules\EmployeeModule\app\Models\Employee;
use Prettus\Repository\Eloquent\BaseRepository;

class EmployeeRepository extends BaseRepository
{
    public function model()
    {
        return Employee::class;
    }

    // employees of one account only
    public function forAccount($accountId)
    {
        return Employee::with('user', 'position', 'shift', 'role')->where('account_id', $accountId);
    }
}
