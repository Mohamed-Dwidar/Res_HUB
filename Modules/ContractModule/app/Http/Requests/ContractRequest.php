<?php

namespace Modules\ContractModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ContractModule\app\Models\Contract;
use Modules\ContractModule\app\Services\ContractService;

/**
 * A contract draft (Create / Renew contract, Edit draft; account + employees): the term, the party (individual | company), the
 * contract's OWN data (2026-11-01: unit number, contract date, duration, value + currency, deposit, the tenant, unit address,
 * activity, notes), "Also save to member",
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

        // a renewal annex (user 2026-11-25): its page sends only its text (the annex template's, the renewal's values filled in, edited
        // for this annex); everything else = ContractService::annexData()
        if ($this->isAnnex()) {
            return [
                'reservation_id' => [$this->route('id') ? 'exclude' : 'required', 'integer'],
                'body_ar' => ['required', 'string', 'max:200000', $known],
            ];
        }

        return [
            'reservation_id' => [$this->route('id') ? 'exclude' : 'required', 'integer'],
            'starts_on' => 'required|date_format:Y-m-d',
            'term_months' => 'nullable|integer|min:1|max:120',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'party_type' => ['required', Rule::in(array_keys(Contract::PARTY_TYPES))],
            // the contract's own data (2026-11-01; pre-filled, edited per contract); required since 2026-11-25 except the tenant's email and
            // title, the deposit and the notes (Contract::REQUIRED_FIELDS; a company: + register, tax card)
            'unit_number' => 'required|string|max:100',
            // required for a contract; an annex: empty = its issue date (ContractService::fillDraft())
            'contract_date' => 'nullable|date_format:Y-m-d',
            'duration_text' => 'required|string|max:100',
            'contract_value' => 'required|numeric|min:0|max:9999999999',
            'currency' => 'required|string|max:50',
            'deposit_amount' => 'nullable|numeric|min:0|max:99999999',
            'tenant_name' => 'required|string|max:255',
            'tenant_representative' => 'required|string|max:255',
            'tenant_title' => 'nullable|string|max:255',
            'tenant_national_id' => 'required|string|max:30',
            'tenant_commercial_register' => 'required_if:party_type,company|nullable|string|max:50',
            'tenant_tax_number' => 'required_if:party_type,company|nullable|string|max:50',
            'tenant_mobile' => 'required|string|max:100',
            'tenant_email' => 'nullable|email:rfc|max:255',
            'unit_address' => 'required|string|max:1000',
            'activity' => 'required|string|max:500',
            // the lessor on this contract (2026-11-25; pre-filled from Settings › Contracts)
            'lessor_name' => 'required|string|max:255',
            'lessor_commercial_register' => 'required|string|max:50',
            'lessor_tax_number' => 'required|string|max:50',
            'lessor_representative' => 'required|string|max:255',
            'lessor_representative_title' => 'required|string|max:255',
            'lessor_representative_national_id' => 'required|string|max:30',
            'notes' => 'nullable|string|max:2000',
            // copy what was typed into the member's / company's EMPTY fields (unticked by default)
            'save_to_member' => 'nullable|boolean',
            // this contract's wording (the template's text until changed; the template itself is never changed)
            'body_ar' => ['required', 'string', 'max:200000', $known],
            'body_en' => ['nullable', 'string', 'max:200000', $known],
        ];
    }

    // the draft is (or will be) a renewal annex: Edit = its kind; Create = the reservation's contract state
    private function isAnnex(): bool
    {
        $service = app(ContractService::class);
        $accountId = (int) $this->user()->userable->ownerAccountId();
        if ($this->route('id')) {
            return Contract::where('account_id', $accountId)->whereKey($this->route('id'))->value('kind') === 'renewal';
        }
        $reservation = \Modules\ReservationModule\app\Models\Reservation::where('account_id', $accountId)->find((int) $this->input('reservation_id'));
        return $reservation && ($service->reservationState($reservation)['state'] ?? null) === 'ended';
    }

    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'The end must be on or after the start.',
            'tenant_commercial_register.required_if' => 'The commercial register is required for a company.',
            'tenant_tax_number.required_if' => 'The tax card no. is required for a company.',
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
            'lessor_name' => 'lessor name',
            'lessor_commercial_register' => 'lessor commercial register',
            'lessor_tax_number' => 'lessor tax registration no.',
            'lessor_representative' => 'lessor legal representative',
            'lessor_representative_title' => 'lessor representative\'s title',
            'lessor_representative_national_id' => 'lessor representative\'s national ID',
            'contract_date' => 'contract date',
            'duration_text' => 'contract duration',
            'contract_value' => 'contract value',
            'tenant_name' => 'tenant name',
            'tenant_representative' => 'legal representative',
            'tenant_title' => 'title',
            'tenant_national_id' => 'national ID',
            'tenant_commercial_register' => 'commercial register',
            'tenant_tax_number' => 'tax card no.',
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
