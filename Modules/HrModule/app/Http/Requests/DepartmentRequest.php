<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// HR › Employee Departments: a department (English and / or Arabic name)
class DepartmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required_without:name_ar|nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return ['name_en.required_without' => 'Write the name (English or Arabic).'];
    }
}
