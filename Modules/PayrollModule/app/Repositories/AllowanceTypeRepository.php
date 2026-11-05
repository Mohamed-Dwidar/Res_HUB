<?php

namespace Modules\PayrollModule\app\Repositories;

use Modules\PayrollModule\app\Models\AllowanceType;
use Prettus\Repository\Eloquent\BaseRepository;

class AllowanceTypeRepository extends BaseRepository
{
    public function model()
    {
        return AllowanceType::class;
    }

    // allowance types of one account only (+ how many salary rows use each)
    public function forAccount($accountId)
    {
        return AllowanceType::withCount('items')->where('account_id', $accountId);
    }
}
