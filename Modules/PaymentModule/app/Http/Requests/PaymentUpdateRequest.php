<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Services\PaymentService;

// the edit of a payment / refund / withholding (the type and the recorder are kept): the owner (in a closed shift a reason is
// required, logged on the shift) or an employee for their own row of their open shift (2026-10-28; no date);
// "For" (2026-11-01, not a refund): reservation | package | '' (other income) like the Add Cash In form (PaymentRequest);
// PaymentService::update() checks the rules again (canEdit(), reason, refund limit, owed, ownership, refunds before a move)
class PaymentUpdateRequest extends FormRequest
{
    private ?Payment $payment = null;

    // the payment being edited (404 in the controller / service when it is not the account's)
    private function payment(): ?Payment
    {
        return $this->payment ??= Payment::where('account_id', $this->user()->userable->ownerAccountId())->find($this->route('id'));
    }

    // "For" sent (cash in / withholding): its reservation / package / other income decide the next fields
    private function sendsFor(): bool
    {
        return $this->payment()?->type !== 'refund' && $this->has('payable');
    }

    // For = Package: the package's own drop menu (package_id) brings its member (as PaymentRequest)
    protected function prepareForValidation(): void
    {
        if ($this->sendsFor() && $this->input('payable') === 'package' && $this->input('package_id')) {
            $memberId = \Modules\PackageModule\app\Models\Package::where('account_id', $this->user()->userable->ownerAccountId())
                ->whereKey((int) $this->input('package_id'))->value('member_id');
            $this->merge(['payable_id' => (int) $this->input('package_id'), 'member_id' => $memberId]);
        }
    }

    public function rules(): array
    {
        $accountId = $this->user()->userable->ownerAccountId();
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');
        $payment = $this->payment();
        $for = $this->sendsFor();
        $payable = $this->input('payable');
        $tables = ['reservation' => 'reservations', 'package' => 'packages'];
        // other income: the form's choice when "For" is sent, else the payment's own
        $otherIncome = $for ? !$payable : !$payment?->payable_type;

        return [
            'amount' => 'required|numeric|min:0.01|max:99999999',
            // employees can't change the date (it stays as recorded, PaymentService::update())
            'paid_at' => $this->routeIs('employee.*') ? ['exclude'] : 'required|date',
            // the current method stays valid even if it was deactivated since
            'payment_method_id' => $payment?->type === 'withholding'
                ? ['exclude']
                : ['required', 'integer', $ownedBy('payment_methods')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $payment?->payment_method_id))],
            'payable' => $for ? ['nullable', Rule::in(array_keys(PaymentService::PAYABLES))] : ['exclude'],
            'payable_id' => $for && isset($tables[$payable])
                ? ['required', 'integer', $ownedBy($tables[$payable])->where('member_id', (int) $this->input('member_id'))]
                : ['exclude'],
            'package_id' => $for && $payable === 'package' ? ['required', 'integer', $ownedBy('packages')] : ['exclude'],
            'member_id' => $for && $payable === 'reservation'
                ? ['required', 'integer', Rule::exists('members', 'id')->where('account_id', $accountId)]
                : ['exclude'],
            'income_category_id' => !$otherIncome
                ? ['exclude']
                : ['required', 'integer', $ownedBy('income_categories')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $payment?->income_category_id))],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            // why a payment of a closed shift is changed (kept in the shift's history)
            // (the owner only: an employee can't change a closed shift's cash in — PaymentService answers 403 with why)
            // a closed shift: required; the owner's other edits may carry one (a date change moving it into a closed shift, 2026-11-08)
            'change_reason' => $this->routeIs('employee.*') ? 'exclude' : ($payment?->shift_closing_id ? 'required|string|max:255' : 'nullable|string|max:255'),
            'confirm_shift_move' => $this->routeIs('employee.*') ? 'exclude' : 'nullable|boolean',
        ];
    }

    public function attributes(): array
    {
        return ['payment_method_id' => 'payment method', 'income_category_id' => 'income category', 'paid_at' => 'date', 'change_reason' => 'reason',
            'payable' => 'payment for', 'payable_id' => $this->input('payable') === 'package' ? 'package' : 'reservation', 'package_id' => 'package',
            'member_id' => 'member'];
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
