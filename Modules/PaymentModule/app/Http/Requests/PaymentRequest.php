<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PaymentModule\app\Services\PaymentService;

// add a payment: of a reservation / a package of a member, or other income (income category);
// PaymentService::create() checks the rules (and the ownership) again, it is also called by imports / invoices
class PaymentRequest extends FormRequest
{
    // For = Package: the package comes from its own drop menu (package_id), or from the hidden payable_id when the form is
    // opened for one package (its view, a row menu), and brings its member; Other Income: no member
    protected function prepareForValidation(): void
    {
        $packageId = (int) ($this->input('package_id') ?: $this->input('payable_id'));
        if ($this->input('payable') === 'package' && $packageId) {
            $accountId = $this->user()->userable->ownerAccountId();
            $memberId = \Modules\PackageModule\app\Models\Package::where('account_id', $accountId)->whereKey($packageId)->value('member_id');
            $this->merge(['package_id' => $packageId, 'payable_id' => $packageId, 'member_id' => $memberId]);
        } elseif (!$this->input('payable')) {
            $this->merge(['member_id' => null]);
        }
    }

    public function rules(): array
    {
        // the account itself, or the account of the logged in employee
        $accountId = $this->user()->userable->ownerAccountId();
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');
        $payable = $this->input('payable');
        $tables = ['reservation' => 'reservations', 'package' => 'packages'];

        return [
            // empty = other income
            'payable' => ['nullable', Rule::in(array_keys(PaymentService::PAYABLES))],
            'payable_id' => isset($tables[$payable])
                ? ['required', 'integer', $ownedBy($tables[$payable])->where('member_id', (int) $this->input('member_id'))]
                : ['exclude'],
            // For = Package: its own drop menu (the error shows under it; prepareForValidation() copied it into payable_id)
            'package_id' => $payable === 'package' ? ['required', 'integer', $ownedBy('packages')] : ['exclude'],
            'income_category_id' => $payable ? ['exclude'] : ['required', 'integer', $ownedBy('income_categories')->where('is_active', true)],
            // required for a reservation / package (its options are the member's), optional for other income
            // (members are not soft deleted)
            'member_id' => [Rule::requiredIf((bool) $payable), 'nullable', 'integer', Rule::exists('members', 'id')->where('account_id', $accountId)],
            'type' => ['required', Rule::in(['payment', 'withholding'])],
            'amount' => 'required|numeric|min:0.01|max:99999999',
            // withholding: no money received (a certificate instead)
            'payment_method_id' => $this->input('type') === 'withholding'
                ? ['exclude']
                : ['required', 'integer', $ownedBy('payment_methods')->where('is_active', true)],
            'paid_at' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            // the form was opened from a reservation / package view (the answer goes back to it)
            'back_to_payable' => 'boolean',
            // the form was opened from the employee's "My Shift" page
            'back_to_shift' => 'boolean',
            'back_to_list' => 'boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'payable' => 'payment for',
            'payable_id' => $this->input('payable') === 'package' ? 'package' : 'reservation',
            'income_category_id' => 'income category',
            'package_id' => 'package',
            'member_id' => 'member',
            'payment_method_id' => 'payment method',
            'paid_at' => 'date',
        ];
    }

    public function messages(): array
    {
        return [
            'payable_id.exists' => $this->input('payable') === 'package' ? 'Choose one of your packages.' : 'Choose one of the member\'s reservations.',
            'payable_id.required' => $this->input('payable') === 'package' ? 'Choose the package.' : 'Choose the reservation.',
            'package_id.required' => 'Choose the package.',
            'package_id.exists' => 'Choose one of your packages.',
            'income_category_id.exists' => 'This income category is not available, choose another one.',
            'payment_method_id.exists' => 'This payment method is not available, choose another one.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
