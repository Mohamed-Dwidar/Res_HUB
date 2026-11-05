<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// pay a line (amount ≤ its remaining) or Pay all (no amount): method + date
class PayrollPayRequest extends FormRequest
{
    public function rules(): array
    {
        return array_filter([
            'amount' => $this->route('line') ? 'required|numeric|min:0.01|max:9999999' : null,
            'payment_method_id' => 'required|integer',
            'paid_on' => 'required|date',
        ]);
    }

    public function attributes(): array
    {
        return ['payment_method_id' => 'payment method', 'paid_on' => 'date'];
    }
}
