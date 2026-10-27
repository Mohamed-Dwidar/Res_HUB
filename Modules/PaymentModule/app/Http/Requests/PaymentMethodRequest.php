<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
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
