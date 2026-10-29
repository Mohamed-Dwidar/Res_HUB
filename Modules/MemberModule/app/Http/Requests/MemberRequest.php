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
            'national_number' => 'nullable|digits_between:5,15',
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
        ];
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'company',
            'job_id' => 'job',
            'from_where' => 'source',
            'message_language' => 'WhatsApp language',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
