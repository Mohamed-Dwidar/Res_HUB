<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// a person's own yearly days of a leave type (empty = the type's default)
class LeaveBalanceRequest extends FormRequest
{
    public function rules(): array
    {
        return ['year' => 'required|integer|min:2000|max:2100', 'days' => 'nullable|numeric|min:0|max:365'];
    }
}
