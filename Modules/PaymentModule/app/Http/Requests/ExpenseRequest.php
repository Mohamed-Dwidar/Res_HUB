<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PaymentModule\app\Models\Expense;

// an expense (add: any login; edit: the account); ExpenseService checks the rules again (category / method of the account, amount)
class ExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        $accountId = $this->user()->userable->ownerAccountId();
        // the expense being edited (null on add); the current category / method stay valid even if deactivated since
        $expense = $this->route('id') ? Expense::where('account_id', $accountId)->find($this->route('id')) : null;
        $ownedBy = fn ($table, $currentId) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId));

        return [
            'expense_category_id' => ['required', 'integer', $ownedBy('expense_categories', $expense?->expense_category_id)],
            'item' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'payment_method_id' => ['required', 'integer', $ownedBy('payment_methods', $expense?->payment_method_id)],
            'spent_at' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'attachment' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp',
            'delete_attachment' => 'boolean',
            // why an expense of a closed shift is changed (kept in the shift's log)
            'change_reason' => $expense?->shift_closing_id ? 'required|string|max:255' : 'exclude',
        ];
    }

    public function attributes(): array
    {
        return ['expense_category_id' => 'category', 'payment_method_id' => 'payment method', 'spent_at' => 'date', 'change_reason' => 'reason', 'attachment' => 'receipt'];
    }

    public function messages(): array
    {
        return [
            'expense_category_id.exists' => 'This expense category is not available, choose another one.',
            'payment_method_id.exists' => 'This payment method is not available, choose another one.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
