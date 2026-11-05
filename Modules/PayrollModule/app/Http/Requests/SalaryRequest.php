<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// a salary row (Salary tab): from a date, basic + allowances[type id] (empty / 0 = none); the service checks the types + the date
class SalaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'effective_from' => 'required|date',
            'basic' => 'required|numeric|min:0|max:9999999',
            'allowances' => 'nullable|array',
            'allowances.*' => 'nullable|numeric|min:0|max:9999999',
            'reason' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return ['allowances.*.numeric' => 'Write an amount.', 'allowances.*.min' => 'The amount can\'t be below 0.'];
    }
}
