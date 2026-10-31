<?php

namespace Modules\ContractModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ContractModule\app\Services\ContractService;

/**
 * A contract template (add / edit): name, titles, the texts (rich text: sanitized here first, so "required" sees what will be
 * saved; an unknown {placeholder} is refused under its text), the default term, on / off, its units (a unit that already has
 * another template is refused under the units: ContractService::templateUnitsError(), checked again when saving).
 */
class ContractTemplateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (ContractService::BODIES as $field) {
            if ($this->has($field)) {
                $clean[$field] = ContractService::sanitize(is_string($this->input($field)) ? $this->input($field) : null) ?: null;
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        $accountId = $this->user()->userable->ownerAccountId();
        $known = function ($attribute, $value, $fail) {
            if ($unknown = ContractService::unknownPlaceholders($value)) {
                $fail(ContractService::unknownMessage($unknown));
            }
        };

        return [
            'name' => 'required|string|max:100',
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'body_ar' => ['required', 'string', 'max:200000', $known],
            'body_en' => ['nullable', 'string', 'max:200000', $known],
            'renewal_body_ar' => ['nullable', 'string', 'max:200000', $known],
            'renewal_body_en' => ['nullable', 'string', 'max:200000', $known],
            'default_term_months' => 'nullable|integer|min:1|max:120',
            'is_enabled' => 'required|boolean',
            'units' => ['nullable', 'array', function ($attribute, $value, $fail) use ($accountId) {
                if (($error = app(ContractService::class)->templateUnitsError($accountId, array_map('intval', (array) $value),
                        $this->route('id') ? (int) $this->route('id') : null))) {
                    $fail($error);
                }
            }],
            'units.*' => ['integer', Rule::exists('units', 'id')->where('account_id', $accountId)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'body_ar.required' => 'The Arabic contract text is required.',
        ];
    }

    public function attributes(): array
    {
        return [
            'title_ar' => 'title (Arabic)',
            'title_en' => 'title (English)',
            'body_ar' => 'Arabic text',
            'body_en' => 'English text',
            'renewal_body_ar' => 'Arabic renewal annex',
            'renewal_body_en' => 'English renewal annex',
            'default_term_months' => 'term',
            'units.*' => 'unit',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
