<?php

namespace Modules\PayrollModule\app\Repositories;

use Modules\PayrollModule\app\Models\EmployeeSalary;
use Prettus\Repository\Eloquent\BaseRepository;

class EmployeeSalaryRepository extends BaseRepository
{
    public function model()
    {
        return EmployeeSalary::class;
    }

    // salary rows of one account only
    public function forAccount($accountId)
    {
        return EmployeeSalary::with('items.type')->where('account_id', $accountId);
    }
}
