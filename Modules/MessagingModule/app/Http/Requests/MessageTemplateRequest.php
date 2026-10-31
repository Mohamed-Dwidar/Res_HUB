<?php

namespace Modules\MessagingModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MessagingModule\app\Services\MessagingService;

/**
 * A message (add / edit): name, "Suggest when" (optional), on / off, texts, units. An unknown {placeholder} is refused under its
 * text; a unit that already has another message with this "Suggest when" is refused under the units
 * (MessagingService::templateUnitsError(), checked again when saving).
 */
class MessageTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        $accountId = $this->user()->userable->ownerAccountId();
        $known = function ($attribute, $value, $fail) {
            if ($unknown = MessagingService::unknownPlaceholders($value)) {
                $fail('Unknown placeholder(s): {' . implode('}, {', $unknown) . '}. Use the ones listed under the text.');
            }
        };

        return [
            'name' => 'required|string|max:100',
            'suggest_when' => ['nullable', Rule::in(array_keys(MessagingService::suggestOptions()))],
            'is_enabled' => 'required|boolean',
            'body_ar' => ['required', 'string', 'max:2000', $known],
            'body_en' => ['nullable', 'string', 'max:2000', $known],
            'units' => ['nullable', 'array', function ($attribute, $value, $fail) use ($accountId) {
                if (($error = app(MessagingService::class)->templateUnitsError($accountId, is_string($this->input('suggest_when')) ? ($this->input('suggest_when') ?: null) : null,
                        array_map('intval', (array) $value), $this->route('id') ? (int) $this->route('id') : null))) {
                    $fail($error);
                }
            }],
            'units.*' => ['integer', Rule::exists('units', 'id')->where('account_id', $accountId)->whereNull('deleted_at')],
        ];
    }

    public function attributes(): array
    {
        return [
            'body_ar' => 'Arabic text',
            'body_en' => 'English text',
            'units.*' => 'unit',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
