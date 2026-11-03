<?php

namespace Modules\EmployeeModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Settings › Roles add / edit (docs/roles-plan.md): permissions[key] = 1 / 0 (unknown keys dropped, needed ones added by RoleService)
class RoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                Rule::unique('roles', 'name')->where('account_id', $this->user()->userable_id)->whereNull('deleted_at')->ignore($this->route('id'))],
            'permissions' => 'nullable|array',
            'idle_hours' => 'nullable|integer|min:1|max:24',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a role with this name.',
        ];
    }

    public function attributes(): array
    {
        return ['idle_hours' => 'idle timeout'];
    }

    public function authorize(): bool
    {
        return true;
    }
}
