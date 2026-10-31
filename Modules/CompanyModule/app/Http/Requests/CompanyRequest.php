<?php

namespace Modules\CompanyModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompanyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            // contracts (ContractModule, 2026-10-31)
            'commercial_register' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
            'representative_name' => 'nullable|string|max:255',
            'representative_title' => 'nullable|string|max:255',
            'representative_national_id' => 'nullable|string|max:30',
        ];
    }

    public function attributes(): array
    {
        return [
            'name_ar' => 'name (Arabic)',
            'name_en' => 'name (English)',
            'representative_national_id' => 'representative\'s national ID',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
