<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Change Status of a staff file (the reason is required to end the work; not Active = the login off: StaffService::changeStatus())
class StaffStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(config('hrmodule.statuses')))],
            'status_date' => 'required|date',
            'status_reason' => 'nullable|string|max:255',
        ];
    }
}
