<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// the social insurance registration (Salary tab); the details are optional (missing ones = warnings, not errors)
class InsuranceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_insured' => 'nullable|boolean',
            'insurance_no' => 'nullable|string|max:50',
            'start_date' => 'nullable|date',
            'insured_wage' => 'nullable|numeric|min:0|max:9999999',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return ['end_date.after_or_equal' => 'The end date can\'t be before the start date.'];
    }
}
