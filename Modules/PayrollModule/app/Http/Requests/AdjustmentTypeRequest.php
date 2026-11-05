<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// HR › Adjustment Types: name (English and / or Arabic) + kind bonus | deduction
class AdjustmentTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required_without:name_ar|nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'kind' => 'required|in:bonus,deduction',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return ['name_en.required_without' => 'Write the name (English or Arabic).'];
    }
}
