<?php

namespace Modules\MessagingModule\app\Repositories;

use Modules\MessagingModule\app\Models\MessageTemplate;
use Prettus\Repository\Eloquent\BaseRepository;

class MessageTemplateRepository extends BaseRepository
{
    public function model()
    {
        return MessageTemplate::class;
    }

    // the WhatsApp templates of one account only
    public function forAccount($accountId)
    {
        return MessageTemplate::where('account_id', $accountId)->where('channel', 'whatsapp');
    }
}
