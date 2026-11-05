<?php

namespace Modules\PayrollModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// a draft payroll line: the typed income tax (0 = none) + notes
class PayrollLineRequest extends FormRequest
{
    public function rules(): array
    {
        return ['income_tax' => 'required|numeric|min:0|max:9999999', 'notes' => 'nullable|string|max:1000'];
    }
}
