<?php

namespace Modules\EmployeeModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeePositionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
            // optional: one of the account's departments (HR › Employee Departments)
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('account_id', $this->user()->userable->ownerAccountId())->whereNull('deleted_at')],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
