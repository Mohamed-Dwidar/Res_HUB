<?php

namespace Modules\UnitModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MessagingModule\app\Services\MessagingService;
use Modules\SpaceModule\app\Models\Space;
use Modules\UnitModule\app\Models\Unit;

class UnitRequest extends FormRequest
{
    // 404 when the space is not one of the account's spaces
    public function authorize(): bool
    {
        Space::where('account_id', $this->user()->userable_id)->findOrFail($this->route('spaceId'));
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            // only a subscription type assigned to this space
            'subscription_type_id' => ['nullable', 'integer',
                Rule::exists('space_subscription_type', 'subscription_type_id')->where('space_id', $this->route('spaceId'))],
            // only plans assigned to this space
            'plans' => 'nullable|array',
            'plans.*' => ['integer', Rule::exists('plan_space', 'plan_id')->where('space_id', $this->route('spaceId'))],
            'color_id' => 'required|integer|exists:colors,id',
            'description_ar' => 'nullable|string|max:5000',
            'description_en' => 'nullable|string|max:5000',
            'notes_ar' => 'nullable|string|max:5000',
            'notes_en' => 'nullable|string|max:5000',
            'capacity' => 'required|integer|min:1|max:100000',
            'concurrent_usage' => 'required|integer|min:1|max:1000',
            'lease_period' => ['nullable', Rule::in(array_keys(Unit::LEASE_PERIODS))],
            'is_active' => 'boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|max:2048',
            'delete_images' => 'nullable|array',
            'delete_images.*' => 'integer',
            // MessagingModule (2026-10-31): the WhatsApp messages of this unit, one per type (checked again when saving)
            'message_templates_sent' => 'nullable|boolean',
            'message_templates' => ['nullable', 'array', function ($attribute, $value, $fail) {
                if ($error = app(MessagingService::class)->unitTemplatesError($this->user()->userable->ownerAccountId(), (array) $value)) {
                    $fail($error);
                }
            }],
            'message_templates.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'images.*.image' => 'Each file must be an image.',
            'images.*.max' => 'Each image must not be greater than 2 MB.',
            'images.max' => 'You can upload up to 10 images at once.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name_ar' => 'name (Arabic)',
            'name_en' => 'name (English)',
            'subscription_type_id' => 'subscription type',
            'color_id' => 'color',
            'plans.*' => 'plan',
            'description_ar' => 'description (Arabic)',
            'description_en' => 'description (English)',
            'notes_ar' => 'notes (Arabic)',
            'notes_en' => 'notes (English)',
        ];
    }
}
