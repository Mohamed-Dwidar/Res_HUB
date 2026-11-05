<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// give an advance (Advances & Adjustments tab); the service checks the method + the months
class AdvanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01|max:9999999',
            'given_on' => 'required|date',
            'installments' => 'required|integer|min:1|max:60',
            'first_period' => 'required|date_format:Y-m-d',
            'payment_method_id' => 'required|integer',
            'reason' => 'nullable|string|max:255',
        ];
    }

    public function attributes(): array
    {
        return ['given_on' => 'date', 'first_period' => 'first deduction month', 'payment_method_id' => 'payment method'];
    }
}
