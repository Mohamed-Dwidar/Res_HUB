<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// HR › Leave Types: name (English and / or Arabic), paid, yearly days (empty = no balance), needs a file, active
class LeaveTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required_without:name_ar|nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'is_paid' => 'nullable|boolean',
            'yearly_days' => 'nullable|numeric|min:0|max:365',
            'needs_attachment' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return ['name_en.required_without' => 'Write the name (English or Arabic).'];
    }
}
