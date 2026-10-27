<?php

namespace Modules\AccountModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AccountModule\app\Models\Account;
use Modules\PaymentModule\app\Services\Tax\TaxRule;

// Settings › General of the logged in account (the routes are account only): the tax switches + rates, the Schedule page, the payment controls
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
            // Taxes (docs/payments-plan.md §3); the start date can't be moved into the past (rows already saved keep their taxes)
            'vat_rate' => 'required|numeric|min:0|max:100',
            'table_tax_rate' => 'required|numeric|min:0|max:100',
            'tax_start_date' => ['nullable', 'date', function ($attribute, $value, $fail) {
                $current = $this->user()->userable->tax_start_date?->format('Y-m-d');
                if ($value && $value !== $current && $value < now()->format('Y-m-d')) {
                    $fail('The tax start date can\'t be in the past.');
                }
            }],
            'tax_rounding' => ['required', Rule::in(array_keys(TaxRule::ROUNDINGS))],
            'tax_report_basis' => ['required', Rule::in(array_keys(Account::TAX_REPORT_BASES))],
            'withholding_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function attributes(): array
    {
        return [
            'withholding_enabled' => 'withholding tax', 'vat_enabled' => 'VAT',
            'today_hours_from' => 'timeline from', 'today_hours_to' => 'timeline to', 'renewal_days' => 'renewals window',
            'late_entry_hours' => 'late entry hours', 'expense_review_amount' => 'expenses to review from',
            'vat_rate' => 'VAT rate', 'table_tax_rate' => 'table tax rate', 'tax_start_date' => 'tax start date',
            'tax_rounding' => 'rounding', 'tax_report_basis' => 'tax report month', 'withholding_rate' => 'withholding rate',
        ];
    }
}
