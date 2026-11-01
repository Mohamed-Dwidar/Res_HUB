<?php

namespace Modules\ContractModule\app\Repositories;

use Modules\ContractModule\app\Models\Contract;
use Prettus\Repository\Eloquent\BaseRepository;

class ContractRepository extends BaseRepository
{
    public function model()
    {
        return Contract::class;
    }

    // the contracts of one account only
    public function forAccount($accountId)
    {
        return Contract::where('contracts.account_id', $accountId);
    }
}
