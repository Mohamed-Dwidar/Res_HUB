<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\HrModule\app\Models\Staff;

/**
 * Add / edit a staff file (docs/hr-plan.md §3.1) = an employees row (Staff). The identity, pay and emergency fields (Staff::SENSITIVE) are validated and
 * saved only with the permission hr.sensitive (without it they are not in validated(): the file keeps them).
 * Status is changed by StaffStatusRequest, not here. External (not on payroll): only name, mobile, email are required.
 * System Access (has_access, login_email, role_id, password, change_password) only with users.manage and never on one's own
 * file (canManageAccess()); without it those keys are not in validated(): the login stays as it is.
 */
class StaffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // External: no identity / pay sections (hidden) → their defaults
        if ($this->boolean('is_external')) {
            $this->merge(['id_type' => $this->input('id_type') ?: 'national_id', 'pay_method' => $this->input('pay_method') ?: 'cash']);
        }
        foreach (['phone', 'another_phone', 'emergency_phone', 'wallet_number'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => preg_replace('/\s+/', '', (string) $this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $accountId = $this->user()->userable->ownerAccountId();
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');

        $external = $this->boolean('is_external');
        $rules = [
            'is_external' => 'nullable|boolean',
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'gender' => ['required', Rule::in(array_keys(Employee::GENDERS))],
            'phone' => [$external ? 'required' : 'nullable', 'regex:' . Account::PHONE_REGEX],
            'another_phone' => ['nullable', 'regex:' . Account::PHONE_REGEX],
            'email' => [$external ? 'required' : 'nullable', 'email:rfc', 'max:255'],
            // required for employees (not External); the Department field only filters the titles (not saved)
            'position_id' => [$external ? 'nullable' : 'required', 'integer', $ownedBy('employee_positions')],
            'shift_id' => ['nullable', 'integer', $ownedBy('employee_shifts')],
            'join_date' => 'nullable|date',
            'probation_ends_on' => 'nullable|date|after_or_equal:join_date',
            'notes' => 'nullable|string|max:5000',
        ];

        if (Gate::allows('hr.sensitive')) {
            $rules += [
                'birth_date' => 'nullable|date|before:today',
                'nationality' => 'nullable|string|max:50',
                'id_type' => ['required', Rule::in(array_keys(config('hrmodule.id_types')))],
                'national_id' => ['nullable', 'string', 'max:30', $this->input('id_type') === 'national_id' ? 'regex:/^\d{14}$/' : 'regex:/^[A-Za-z0-9\-]+$/'],
                'marital_status' => ['nullable', Rule::in(array_keys(config('hrmodule.marital_statuses')))],
                'military_status' => ['nullable', Rule::in(array_keys(config('hrmodule.military_statuses')))],
                'educational_qualification' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'pay_method' => ['required', Rule::in(array_keys(config('hrmodule.pay_methods')))],
                'bank_name' => 'nullable|string|max:255',
                'bank_account' => 'nullable|string|max:50',
                'wallet_number' => ['nullable', 'regex:' . Account::PHONE_REGEX],
                'emergency_name' => 'nullable|string|max:255',
                'emergency_relation' => 'nullable|string|max:50',
                'emergency_phone' => ['nullable', 'regex:' . Account::PHONE_REGEX],
            ];
        }

        // Add Staff › Documents (optional rows; the Documents tab's file rules; a row without a type is skipped)
        if (!$this->route('id') && Gate::allows('documents.manage')) {
            $rules += [
                'documents' => 'nullable|array|max:10',
                'documents.*.document_type_id' => 'nullable|integer',
                'documents.*.name' => 'nullable|string|max:255',
                'documents.*.number' => 'nullable|string|max:100',
                'documents.*.expiry_date' => 'nullable|date',
                'documents.*.files' => 'nullable|array|max:10',
                'documents.*.files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:' . (int) config('hrmodule.document_max_kb'),
            ];
        }

        if ($this->canManageAccess()) {
            $login = $this->staff()?->user;
            // a new login needs a password; an existing one only when "Change password" is ticked
            $needPassword = $this->boolean('has_access') && (!$login || $this->boolean('change_password'));
            $rules += [
                'has_access' => 'nullable|boolean',
                'login_email' => ['exclude_unless:has_access,1', 'required', 'email:rfc,filter', 'max:255', Rule::unique('users', 'email')->ignore($login?->id)],
                'role_id' => ['exclude_unless:has_access,1', 'required', 'integer', Rule::exists('roles', 'id')->where('account_id', $accountId)->whereNull('deleted_at')],
                'change_password' => 'nullable|boolean',
                'password' => $needPassword ? 'required|string|min:6|confirmed' : 'exclude',
            ];
        }

        return $rules;
    }

    // the file being edited (null on create)
    public function staff(): ?Staff
    {
        return $this->route('id') ? Staff::where('account_id', $this->user()->userable->ownerAccountId())->find($this->route('id')) : null;
    }

    // users.manage, and not the user's own file (nobody changes their own login / role)
    public function canManageAccess(): bool
    {
        $own = $this->user()->userable instanceof Employee && $this->route('id') && (int) $this->route('id') === (int) $this->user()->userable_id;
        return Gate::allows('users.manage') && !$own;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Write the name.',
            'position_id.required' => 'Choose the job title.',
            'login_email.unique' => 'This email already logs in to the system.',
            'documents.*.files.*.mimes' => 'Each document file must be a PDF or an image (JPG, PNG).',
            'documents.*.files.*.max' => 'Each document file must be 10 MB at most.',
            'login_email.required' => 'Write the email this person logs in with.',
            'phone.required' => 'An external person needs a mobile.',
            'email.required' => 'An external person needs an email.',
            'national_id.regex' => $this->input('id_type') === 'national_id' ? 'A national ID has 14 digits.' : 'Letters, digits and - only.',
            'probation_ends_on.after_or_equal' => 'The probation must end after the hire date.',
        ];
    }
}
