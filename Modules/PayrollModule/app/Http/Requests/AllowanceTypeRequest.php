<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// HR › Allowance Types: an allowance type (English and / or Arabic name)
class AllowanceTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required_without:name_ar|nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'is_insurable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return ['name_en.required_without' => 'Write the name (English or Arabic).'];
    }
}
