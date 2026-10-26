<?php

namespace Modules\PaymentModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// the account reopens a closed shift (ShiftClosingService::reopen()): why, logged on the shift's history
class ShiftReopenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:3|max:255',
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Write why the shift is reopened (e.g. a payment was missing from the count).'];
    }
}
