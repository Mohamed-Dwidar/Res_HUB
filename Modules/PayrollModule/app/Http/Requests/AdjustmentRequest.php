<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// a bonus / deduction for a month: amount OR days (value_kind), reason required; the service checks the type + the month
class AdjustmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'adjustment_type_id' => 'required|integer',
            'period' => 'required|date_format:Y-m-d',
            'value_kind' => 'required|in:amount,days',
            'amount' => 'exclude_unless:value_kind,amount|required|numeric|min:0.01|max:9999999',
            'days' => 'exclude_unless:value_kind,days|required|numeric|min:0.5|max:31',
            'reason' => 'required|string|max:255',
        ];
    }

    public function attributes(): array
    {
        return ['adjustment_type_id' => 'type', 'period' => 'month'];
    }
}
