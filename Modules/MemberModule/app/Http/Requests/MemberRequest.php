<?php

namespace Modules\MemberModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;

class MemberRequest extends FormRequest
{
    // remove the spaces from the phone before it is validated and saved
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => Member::cleanPhone($this->input('phone'))]);
        }
    }

    public function rules(): array
    {
        // the account itself, or the account of the logged in employee
        $accountId = $this->user()->userable->ownerAccountId();

        // company / job must be one of the account's own (not deleted) records
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');

        return [
            'name' => 'required|string|max:255',
            // unique by phone_key (Member::phoneKey(): 0122…, +20122…, 20122… are one number), not by the typed text
            'phone' => [
                'required', 'regex:' . Account::PHONE_REGEX,
                function ($attribute, $value, $fail) use ($accountId) {
                    $taken = Member::where('account_id', $accountId)->where('phone_key', Member::phoneKey($value))
                        ->when($this->route('id'), fn ($query, $id) => $query->whereKeyNot($id))->exists();
                    if ($taken) {
                        $fail('There is already a member with this phone number.');
                    }
                },
            ],
            'email' => 'nullable|email:rfc,filter|max:255',
            // contracts (2026-10-31): a national ID = digits, a passport = letters + digits
            'id_type' => ['nullable', Rule::in(array_keys(Member::ID_TYPES))],
            'national_number' => $this->input('id_type') === 'passport' ? ['nullable', 'regex:/^[A-Za-z0-9]{5,20}$/'] : 'nullable|digits_between:5,15',
            'nationality' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:1000',
            'company_id' => ['nullable', 'integer', $ownedBy('companies')],
            'job_id' => ['nullable', 'integer', $ownedBy('job_positions')],
            'from_where' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:255',
            'message_language' => 'nullable|in:ar,en',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone must be a valid phone number: digits only (8-15), optionally starting with +.',
            'national_number.regex' => 'A passport number is 5-20 letters and digits.',
        ];
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'company',
            'job_id' => 'job',
            'from_where' => 'source',
            'id_type' => 'ID type',
            'national_number' => 'ID number',
            'message_language' => 'WhatsApp language',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
