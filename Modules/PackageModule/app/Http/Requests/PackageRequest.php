<?php

namespace Modules\PackageModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // a member of the same account (the account, or the account of the logged in employee)
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->where('account_id', $this->user()->userable->ownerAccountId())],
            // the discount (2026-11-17): a % or a fixed amount, chosen in the Edit form
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            // the package net (more than the price is lowered to the price)
            'after_discount' => 'nullable|numeric|min:0|max:99999999',
            'discount_fixed' => 'nullable|numeric|min:0|max:99999999',
            'net_typed' => 'nullable|boolean',
            'notes' => 'nullable|string|max:255',
            // Phase C taxes: sent only by the Edit form while taxes apply (PackageService::canSetInvoice() decides)
            'invoice_requested' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
        ];
    }

    public function attributes(): array
    {
        return [
            'member_id' => 'member',
            'after_discount' => 'after discount',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
