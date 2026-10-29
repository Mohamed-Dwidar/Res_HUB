<?php

namespace Modules\MessagingModule\app\Http\Controllers\Employee;

use Illuminate\Routing\Controller;
use Modules\MessagingModule\app\Http\Controllers\Concerns\MessageActions;

// logged in employee: the WhatsApp popup + Send (MessageActions); the settings stay with the account
class MessageEmployeeModuleController extends Controller
{
    use MessageActions;

    protected function area(): string
    {
        return 'employee';
    }
}
