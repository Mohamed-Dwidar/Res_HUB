<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// HR › Document Types: a document type
class DocumentTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required_without:name_ar|nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'has_expiry' => 'nullable|boolean',
            'remind_days' => 'nullable|integer|min:1|max:365',
            'is_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return ['name_en.required_without' => 'Write the name (English or Arabic).'];
    }
}
