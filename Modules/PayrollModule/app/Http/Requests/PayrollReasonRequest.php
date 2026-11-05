<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// reopen a payroll / undo a salary payment: the reason is required (kept with who / when)
class PayrollReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => 'required|string|min:3|max:255'];
    }
}
