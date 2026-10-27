<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\PaymentModule\app\Models\IncomeCategory;

class IncomeCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return IncomeCategory::taxFlagRules() + [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_revenue' => 'boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'name_ar' => 'name (Arabic)',
            'name_en' => 'name (English)',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
