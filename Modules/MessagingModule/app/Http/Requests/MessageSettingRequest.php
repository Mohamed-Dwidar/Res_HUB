<?php

namespace Modules\MessagingModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Settings › WhatsApp Messages (the account only)
class MessageSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_enabled' => 'required|boolean',
            'default_language' => 'required|in:ar,en',
            'deposit_percent' => 'required|numeric|min:0|max:100',
            'payment_details' => 'nullable|string|max:2000',
        ];
    }

    public function attributes(): array
    {
        return [
            'is_enabled' => 'WhatsApp messages',
            'deposit_percent' => 'deposit %',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
