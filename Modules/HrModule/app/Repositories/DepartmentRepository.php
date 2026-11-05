<?php

namespace Modules\HrModule\app\Repositories;

use Modules\HrModule\app\Models\Department;
use Prettus\Repository\Eloquent\BaseRepository;

class DepartmentRepository extends BaseRepository
{
    public function model()
    {
        return Department::class;
    }

    // departments of one account only (+ how many people / job titles are in each)
    public function forAccount($accountId)
    {
        return Department::withCount(['staff', 'positions'])->where('account_id', $accountId);
    }
}
