<?php

namespace Modules\EmployeeModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;

class EmployeeRequest extends FormRequest
{
    // remove the spaces from the phones before they are validated and saved
    protected function prepareForValidation(): void
    {
        foreach (['phone', 'another_phone'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => preg_replace('/\s+/', '', (string) $this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $accountId = $this->user()->userable_id;
        // the login of this employee (edit), so his own email is not "taken"
        $userId = $this->route('id')
            ? Employee::where('account_id', $accountId)->find($this->route('id'))?->user?->id
            : null;

        // position / shift must be one of the account's own (not deleted)
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');

        return [
            'name' => 'required|string|max:255',
            'phone' => ['required', 'regex:' . Account::PHONE_REGEX],
            'another_phone' => ['nullable', 'regex:' . Account::PHONE_REGEX],
            'gender' => ['required', Rule::in(array_keys(Employee::GENDERS))],
            'birth_date' => 'nullable|date|before:today',
            'address' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:2048',
            'position_id' => ['nullable', 'integer', $ownedBy('emp_positions')],
            'shift_id' => ['nullable', 'integer', $ownedBy('emp_shifts')],
            'role_id' => ['required', 'integer', $ownedBy('roles')],
            'salary' => 'nullable|numeric|min:0|max:99999999',
            'educational_qualification' => 'nullable|string|max:255',
            'join_date' => 'nullable|date',
            'leave_date' => 'nullable|date|after_or_equal:join_date',

            // login
            'email' => ['required', 'email:rfc,filter', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$this->route('id') ? 'nullable' : 'required', 'string', 'min:6', 'confirmed'],

            // attachments: new rows [label, file] + saved ones to remove
            'attachments' => 'nullable|array|max:10',
            'attachments.*.label' => 'nullable|string|max:255',
            'attachments.*.file' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx',
            'delete_attachments' => 'nullable|array',
            'delete_attachments.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone must be a valid phone number: digits only (8-15), optionally starting with +.',
            'another_phone.regex' => 'The another phone must be a valid phone number: digits only (8-15), optionally starting with +.',
            'attachments.*.file.max' => 'Each attachment must not be greater than 5 MB.',
            'attachments.*.file.mimes' => 'Each attachment must be a PDF, image, Word or Excel file.',
            'attachments.max' => 'You can add up to 10 attachments at once.',
            'leave_date.after_or_equal' => 'The leave date must be on or after the join date.',
        ];
    }

    public function attributes(): array
    {
        return [
            'position_id' => 'position',
            'shift_id' => 'shift',
            'role_id' => 'role',
            'educational_qualification' => 'educational qualification',
            'attachments.*.label' => 'attachment label',
            'attachments.*.file' => 'attachment',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
