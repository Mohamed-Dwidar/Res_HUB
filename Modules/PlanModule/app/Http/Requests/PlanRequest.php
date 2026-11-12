<?php

namespace Modules\PlanModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PlanModule\app\Models\Plan;

class PlanRequest extends FormRequest
{
    public function rules(): array
    {
        return Plan::taxFlagRules() + [
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1|max:100000',
            'lease_period' => ['required', Rule::in(array_keys(Plan::LEASE_PERIODS))],
            'amount' => 'required|numeric|min:0|max:99999999',
            'facilities' => 'nullable|string|max:5000',
            'is_time_based' => 'boolean',
            'is_active' => 'boolean',
            'renewal_increase_type' => ['nullable', Rule::in(array_keys(\Modules\ReservationModule\app\Models\Reservation::RENEWAL_INCREASE_TYPES))],
            'renewal_increase_value' => 'nullable|numeric|min:0|max:99999999',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
