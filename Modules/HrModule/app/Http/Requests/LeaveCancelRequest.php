<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// cancelling a leave: the reason is required (kept with who / when)
class LeaveCancelRequest extends FormRequest
{
    public function rules(): array
    {
        return ['cancel_reason' => 'required|string|min:3|max:255'];
    }

    public function attributes(): array
    {
        return ['cancel_reason' => 'reason'];
    }
}
