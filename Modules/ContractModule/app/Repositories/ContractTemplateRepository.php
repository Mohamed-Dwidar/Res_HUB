<?php

namespace Modules\ContractModule\app\Repositories;

use Modules\ContractModule\app\Models\ContractTemplate;
use Prettus\Repository\Eloquent\BaseRepository;

class ContractTemplateRepository extends BaseRepository
{
    public function model()
    {
        return ContractTemplate::class;
    }

    // the templates of one account only
    public function forAccount($accountId)
    {
        return ContractTemplate::where('account_id', $accountId);
    }
}
