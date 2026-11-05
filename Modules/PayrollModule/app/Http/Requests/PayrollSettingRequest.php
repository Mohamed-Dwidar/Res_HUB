<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// HR › Payroll Settings: insurance % (empty = not set yet), the days of a month for the daily rate
class PayrollSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'insurance_employee_percent' => 'nullable|numeric|min:0|max:100',
            'insurance_company_percent' => 'nullable|numeric|min:0|max:100',
            'month_days' => 'required|integer|min:1|max:31',
        ];
    }
}
