<?php

namespace Modules\AccountModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Settings › General of the logged in account (the routes are account only): the two tax switches + the Schedule page settings
class GeneralSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'withholding_enabled' => 'required|boolean',
            'vat_enabled' => 'required|boolean',
            'today_hours_from' => 'required|integer|min:0|max:23',
            'today_hours_to' => 'required|integer|gt:today_hours_from|max:30',
            'renewal_days' => 'required|integer|min:1|max:90',
            'late_entry_hours' => 'required|integer|min:0|max:720',
            'expense_review_amount' => 'required|numeric|min:0|max:99999999',
        ];
    }

    public function attributes(): array
    {
        return [
            'withholding_enabled' => 'withholding tax', 'vat_enabled' => 'VAT',
            'today_hours_from' => 'timeline from', 'today_hours_to' => 'timeline to', 'renewal_days' => 'renewals window',
            'late_entry_hours' => 'late entry hours', 'expense_review_amount' => 'expenses to review from',
        ];
    }
}
