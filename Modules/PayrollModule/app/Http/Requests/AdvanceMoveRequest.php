<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// an advance move: repay (cash: method, date) | change (month, the new installment, 0 = pause)
class AdvanceMoveRequest extends FormRequest
{
    public function rules(): array
    {
        $kind = $this->route()->parameter('kind');
        return array_filter([
            'amount' => 'required|numeric|min:' . ($kind === 'change' ? 0 : 0.01) . '|max:9999999',
            'payment_method_id' => $kind === 'repay' ? 'required|integer' : null,
            'paid_at' => $kind === 'repay' ? 'nullable|date' : null,
            'reference' => $kind === 'repay' ? 'nullable|string|max:255' : null,
            'period' => $kind === 'repay' ? null : 'required|date_format:Y-m-d',
            'reason' => $kind === 'repay' ? 'nullable|string|max:255' : 'required|string|max:255',
        ]);
    }

    public function attributes(): array
    {
        return ['payment_method_id' => 'payment method', 'paid_at' => 'date', 'period' => 'month'];
    }
}
