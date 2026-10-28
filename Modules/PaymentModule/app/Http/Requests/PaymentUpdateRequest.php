<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PaymentModule\app\Models\Payment;

// the edit of a payment / refund / withholding (the payable, member and type are kept): the owner (in a closed shift a reason is
// required, logged on the shift) or an employee for their own cash in of their open shift (2026-10-28; no date);
// PaymentService::update() checks the rules again (canEdit(), reason, refund limit, owed, ownership)
class PaymentUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $accountId = $this->user()->userable->ownerAccountId();
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');
        // the payment being edited (404 in the controller / service when it is not the account's)
        $payment = Payment::where('account_id', $accountId)->find($this->route('id'));

        return [
            'amount' => 'required|numeric|min:0.01|max:99999999',
            // employees can't change the date (it stays as recorded, PaymentService::update())
            'paid_at' => $this->routeIs('employee.*') ? ['exclude'] : 'required|date',
            // the current method stays valid even if it was deactivated since
            'payment_method_id' => $payment?->type === 'withholding'
                ? ['exclude']
                : ['required', 'integer', $ownedBy('payment_methods')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $payment?->payment_method_id))],
            'income_category_id' => $payment?->payable_type
                ? ['exclude']
                : ['required', 'integer', $ownedBy('income_categories')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $payment?->income_category_id))],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            // why a payment of a closed shift is changed (kept in the shift's history)
            // (the owner only: an employee can't change a closed shift's cash in — PaymentService answers 403 with why)
            'change_reason' => $payment?->shift_closing_id && !$this->routeIs('employee.*') ? 'required|string|max:255' : 'exclude',
        ];
    }

    public function attributes(): array
    {
        return ['payment_method_id' => 'payment method', 'income_category_id' => 'income category', 'paid_at' => 'date', 'change_reason' => 'reason'];
    }

    public function messages(): array
    {
        return [
            'income_category_id.exists' => 'This income category is not available, choose another one.',
            'payment_method_id.exists' => 'This payment method is not available, choose another one.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
