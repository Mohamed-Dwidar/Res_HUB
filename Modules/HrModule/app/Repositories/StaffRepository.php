<?php

namespace Modules\HrModule\app\Repositories;

use Modules\HrModule\app\Models\Staff;
use Prettus\Repository\Eloquent\BaseRepository;

class StaffRepository extends BaseRepository
{
    public function model()
    {
        return Staff::class;
    }

    // the people (employees rows, with or without a login) of one account only
    public function forAccount($accountId)
    {
        return Staff::where('employees.account_id', $accountId);
    }
}
