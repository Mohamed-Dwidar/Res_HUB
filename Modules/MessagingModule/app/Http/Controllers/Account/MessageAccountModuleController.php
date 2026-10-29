<?php

namespace Modules\MessagingModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Modules\MessagingModule\app\Http\Controllers\Concerns\MessageActions;

// logged in account: the WhatsApp popup + Send (MessageActions)
class MessageAccountModuleController extends Controller
{
    use MessageActions;

    protected function area(): string
    {
        return 'account';
    }
}
