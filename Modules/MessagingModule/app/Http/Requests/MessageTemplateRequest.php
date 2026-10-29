<?php

namespace Modules\MessagingModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MessagingModule\app\Models\MessageTemplate;
use Modules\MessagingModule\app\Services\MessagingService;

/**
 * A message text: add = a space version (type + space, one per type and space); edit = the texts and on / off only (the type /
 * space never change, excluded). An unknown {placeholder} is refused under its text.
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
        $editing = (bool) $this->route('id');

        return [
            'type' => $editing ? 'exclude' : ['required', Rule::in(array_keys(MessagingService::types()))],
            'space_id' => $editing ? 'exclude' : ['required', 'integer',
                Rule::exists('spaces', 'id')->where('account_id', $accountId)->whereNull('deleted_at'),
                function ($attribute, $value, $fail) use ($accountId) {
                    $taken = MessageTemplate::where('account_id', $accountId)->where('channel', MessagingService::CHANNEL)
                        ->where('type', $this->input('type'))->where('space_id', (int) $value)->exists();
                    if ($taken) {
                        $fail('This space already has its own text for this type: edit it from the list.');
                    }
                }],
            'is_enabled' => 'required|boolean',
            'body_ar' => ['required', 'string', 'max:2000', $known],
            'body_en' => ['nullable', 'string', 'max:2000', $known],
        ];
    }

    public function attributes(): array
    {
        return [
            'space_id' => 'space',
            'body_ar' => 'Arabic text',
            'body_en' => 'English text',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
