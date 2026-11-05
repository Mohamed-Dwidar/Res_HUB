<?php

namespace Modules\HrModule\app\Repositories;

use Modules\HrModule\app\Models\DocumentType;
use Prettus\Repository\Eloquent\BaseRepository;

class DocumentTypeRepository extends BaseRepository
{
    public function model()
    {
        return DocumentType::class;
    }

    // document types of one account only (+ how many documents use each)
    public function forAccount($accountId)
    {
        return DocumentType::withCount('documents')->where('account_id', $accountId);
    }
}
