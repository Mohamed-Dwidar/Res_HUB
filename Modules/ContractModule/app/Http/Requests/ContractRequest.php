<?php

namespace Modules\ContractModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ContractModule\app\Models\Contract;
use Modules\ContractModule\app\Services\ContractService;

/**
 * A contract draft (Create / Renew contract, Edit draft; account + employees): the term, the party (individual | company), the
 * contract's OWN data (2026-11-01: unit number, contract date, duration, value + currency, deposit, the tenant, unit address,
 * activity, notes — optional here, required only to issue: ContractService::missingToIssue()), "Also save to member / company",
 * this contract's wording (body_ar / body_en, the editors). ContractService checks the reservation, the overlap and the company again.
 */
class ContractRequest extends FormRequest
{
    // the wording (the page's editors): sanitized first, so "required" sees what will be saved
    protected function prepareForValidation(): void
    {
        foreach (['body_ar', 'body_en'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => ContractService::sanitize(is_string($this->input($field)) ? $this->input($field) : null) ?: null]);
            }
        }
    }

    public function rules(): array
    {
        $known = function ($attribute, $value, $fail) {
            if ($unknown = ContractService::unknownPlaceholders($value)) {
                $fail(ContractService::unknownMessage($unknown));
            }
        };

        return [
            'reservation_id' => [$this->route('id') ? 'exclude' : 'required', 'integer'],
            'starts_on' => 'required|date_format:Y-m-d',
            'term_months' => 'nullable|integer|min:1|max:120',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'party_type' => ['required', Rule::in(array_keys(Contract::PARTY_TYPES))],
            // the contract's own data (2026-11-01; pre-filled, edited per contract; required only to issue: ContractService::missingToIssue())
            'unit_number' => 'nullable|string|max:100',
            'contract_date' => 'nullable|date_format:Y-m-d',
            'duration_text' => 'nullable|string|max:100',
            'contract_value' => 'nullable|numeric|min:0|max:9999999999',
            'currency' => 'nullable|string|max:50',
            'deposit_amount' => 'nullable|numeric|min:0|max:99999999',
            'tenant_name' => 'nullable|string|max:255',
            'tenant_representative' => 'nullable|string|max:255',
            'tenant_company' => 'nullable|string|max:255',
            'tenant_title' => 'nullable|string|max:255',
            'tenant_national_id' => 'nullable|string|max:30',
            'tenant_mobile' => 'nullable|string|max:30',
            'tenant_email' => 'nullable|email:rfc|max:255',
            'unit_address' => 'nullable|string|max:1000',
            'activity' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            // copy what was typed into the member's / company's EMPTY fields (unticked by default)
            'save_to_member' => 'nullable|boolean',
            // this contract's wording (the template's text until changed; the template itself is never changed)
            'body_ar' => ['required', 'string', 'max:200000', $known],
            'body_en' => ['nullable', 'string', 'max:200000', $known],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'The end must be on or after the start.',
            'body_ar.required' => 'The Arabic contract text is required.',
        ];
    }

    public function attributes(): array
    {
        return [
            'starts_on' => 'start',
            'ends_on' => 'end',
            'term_months' => 'term',
            'deposit_amount' => 'deposit',
            'unit_number' => 'unit number',
            'contract_date' => 'contract date',
            'duration_text' => 'contract duration',
            'contract_value' => 'contract value',
            'tenant_name' => 'tenant name',
            'tenant_representative' => 'legal representative',
            'tenant_company' => 'company name',
            'tenant_title' => 'title',
            'tenant_national_id' => 'national ID',
            'tenant_mobile' => 'mobile',
            'tenant_email' => 'email',
            'unit_address' => 'unit address',
            'body_ar' => 'Arabic text',
            'body_en' => 'English text',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
