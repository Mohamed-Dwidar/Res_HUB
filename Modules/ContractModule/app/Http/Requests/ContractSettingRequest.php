<?php

namespace Modules\ContractModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings › Contracts: the lessor data printed on the contracts + the first contract number (set when the old contracts are
 * imported: new numbers continue after the highest one).
 */
class ContractSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lessor_name_ar' => 'required|string|max:255',
            'lessor_name_en' => 'nullable|string|max:255',
            'commercial_register' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'representative_name' => 'nullable|string|max:255',
            'representative_title' => 'nullable|string|max:255',
            'representative_national_id' => 'nullable|string|max:30',
            'property_address_ar' => 'nullable|string|max:1000',
            'property_address_en' => 'nullable|string|max:1000',
            'number_start' => 'required|integer|min:1|max:99999999',
        ];
    }

    public function attributes(): array
    {
        return [
            'lessor_name_ar' => 'lessor name (Arabic)',
            'lessor_name_en' => 'lessor name (English)',
            'representative_national_id' => 'representative\'s national ID',
            'property_address_ar' => 'property address (Arabic)',
            'property_address_en' => 'property address (English)',
            'number_start' => 'first contract number',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
