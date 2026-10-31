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
        ];
    }

    public function attributes(): array
    {
        return [
            'is_enabled' => 'WhatsApp messages',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
