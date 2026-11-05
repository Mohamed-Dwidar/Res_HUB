<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// cancelling an advance / a move / a bonus or deduction: the reason is required (kept with who / when)
class CancelRequest extends FormRequest
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
