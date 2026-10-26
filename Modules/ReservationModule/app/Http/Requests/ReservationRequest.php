<?php

namespace Modules\ReservationModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\PlanModule\app\Models\Plan;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\ReservationModule\app\Services\ReservationService;
use Modules\UnitModule\app\Models\Unit;

class ReservationRequest extends FormRequest
{
    // "Book For" = Member: the package list is hidden, a package left in it is not saved
    protected function prepareForValidation(): void
    {
        if ($this->input('book_for') === 'member') {
            $this->merge(['package_id' => null]);
        }
    }

    public function rules(): array
    {
        // the account itself, or the account of the logged in employee
        $accountId = $this->user()->userable->ownerAccountId();
        $ownedBy = fn ($table) => Rule::exists($table, 'id')->where('account_id', $accountId)->whereNull('deleted_at');
        $timeBased = $this->timeBased();
        $repeating = $this->repeating();

        return [
            'subscription_type_id' => ['required', 'integer', Rule::exists('subscription_types', 'id')->where('account_id', $accountId)],
            // the chain: a space that has the subscription type, a unit of the space, a plan chosen for the unit (all active)
            'space_id' => ['required', 'integer', $ownedBy('spaces')->where('is_active', true),
                Rule::exists('space_subscription_type', 'space_id')->where('subscription_type_id', (int) $this->input('subscription_type_id'))],
            'unit_id' => ['required', 'integer', $ownedBy('units')->where('space_id', (int) $this->input('space_id'))->where('is_active', true)
                ->where('subscription_type_id', (int) $this->input('subscription_type_id'))],
            'plan_id' => ['required', 'integer', $ownedBy('plans')->where('is_active', true),
                Rule::exists('plan_unit', 'plan_id')->where('unit_id', (int) $this->input('unit_id'))],
            // "Book For" (the add / edit form, 2026-10-22): Package → a package is required; Member → no package (prepareForValidation())
            'book_for' => ['exclude'],
            'package_id' => [Rule::requiredIf($this->input('book_for') === 'package'), 'nullable', 'integer', $ownedBy('packages')],
            // not needed with a package (the package's member is used)
            'member_id' => ['required_without:package_id', 'nullable', 'integer', Rule::exists('members', 'id')->where('account_id', $accountId)],

            'start_at' => 'required|date',
            // always (2026-10-15: no more "continues" without an end; a subscription is renewed by a new period)
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            // the times: only for a time based plan
            // the times: only for a time based plan (else they are ignored, the form fills them even when hidden);
            // the time inputs always send H:i (24 hours), the lists show them as h:i A
            'start_time' => $timeBased ? ['required', 'date_format:H:i'] : ['exclude'],
            'end_time' => $timeBased ? ['required', 'date_format:H:i'] : ['exclude'],
            // repeat: on create only (and when the subscription type can repeat)
            'is_repeat' => 'boolean',
            'repeat_frequency' => [Rule::requiredIf($repeating), 'nullable', Rule::in(array_keys(Reservation::REPEAT_FREQUENCIES))],
            'repeat_interval' => [Rule::requiredIf($repeating), 'nullable', 'integer', 'min:1', 'max:365'],
            'repeat_until' => [Rule::requiredIf($repeating), 'nullable', 'date', 'after:start_at'],
            'number_of_peoples' => 'required|integer|min:1|max:100000',

            'amount' => 'required|numeric|min:0|max:99999999',
            // the one typed last, the others are calculated from it
            'discount_type' => 'nullable|in:percentage,value,net',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_value' => 'nullable|numeric|min:0|lte:amount',
            'net_amount' => 'nullable|numeric|min:0|lte:amount',
            'notes' => 'nullable|string|max:5000',
            // the period it renews: the Renew form (hidden, both areas) / "Renewal of #" in the account's edit form; employees'
            // edits keep it; checked by ReservationService::renewalError()
            'renewal_of' => $this->route('id') && !$this->routeIs('account.*') ? ['exclude'] : ['nullable', 'integer', 'min:1'],
            // the anchor day of a period ("Billing day", 2026-10-23): the account's edit form only; else set from the start day /
            // kept as saved (ReservationService)
            'subscription_day' => $this->route('id') && $this->routeIs('account.*') ? ['nullable', 'integer', 'between:1,31'] : ['exclude'],
        ];
    }

    // repeat: on create (no reservation id in the route), checked and its subscription type can repeat
    private function repeating(): bool
    {
        return !$this->route('id') && $this->boolean('is_repeat')
            && (bool) SubscriptionType::find($this->input('subscription_type_id'))?->can_repeat;
    }

    private function timeBased(): bool
    {
        return (bool) Plan::find($this->input('plan_id'))?->is_time_based;
    }

    // the people can not be more than the unit capacity; a time based reservation ends after it starts
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // the repeat until date must give 1 - MAX_REPEATS copies
            if ($this->repeating() && !$validator->errors()->hasAny(['start_at', 'repeat_frequency', 'repeat_interval', 'repeat_until'])) {
                $count = count(Reservation::repeatStarts(Carbon::parse($this->input('start_at')), $this->input('repeat_frequency'),
                    (int) $this->input('repeat_interval'), Carbon::parse($this->input('repeat_until'))));
                if ($count === 0) {
                    $validator->errors()->add('repeat_until', 'There is no repeat before this date, choose a later date.');
                } elseif ($count > Reservation::MAX_REPEATS) {
                    $validator->errors()->add('repeat_until', 'Too many repeats (more than ' . Reservation::MAX_REPEATS . '), choose an earlier date.');
                }
            }
            if ($this->timeBased() && !$validator->errors()->hasAny(['start_at', 'end_at', 'start_time', 'end_time'])
                && $this->input('end_at') . ' ' . $this->input('end_time') <= $this->input('start_at') . ' ' . $this->input('start_time')) {
                $validator->errors()->add('end_time', 'The end must be after the start.');
            }

            if ($validator->errors()->hasAny(['unit_id', 'number_of_peoples'])) {
                return;
            }
            $capacity = Unit::find($this->input('unit_id'))?->capacity;
            if ($capacity && (int) $this->input('number_of_peoples') > $capacity) {
                $validator->errors()->add('number_of_peoples', 'The unit capacity is ' . $capacity . ' persons.');
            }

            // the unit must be free in the period (+ the repeats on create); the message is shown at the top of the form,
            // as HTML ("html_" key: the reservations are links, the texts are escaped by the service)
            if ($validator->errors()->isEmpty()) {
                $error = app(ReservationService::class)->availabilityError($this->all(), $this->route('id'));
                if ($error) {
                    $validator->errors()->add('html_availability', $error);
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'space_id.exists' => 'The selected space is inactive or does not have this subscription type.',
            'unit_id.exists' => 'The selected unit is inactive, not in this space or not for this subscription type.',
            'plan_id.exists' => 'The selected plan is inactive or not available for this unit.',
            'member_id.required_without' => 'Select a member or a package.',
            'package_id.required' => 'Choose the package.',
            'discount_value.lte' => 'The discount can not be more than the amount.',
            'net_amount.lte' => 'The net amount can not be more than the amount.',
        ];
    }

    public function attributes(): array
    {
        return [
            'subscription_type_id' => 'subscription type',
            'space_id' => 'space',
            'unit_id' => 'unit',
            'plan_id' => 'plan',
            'package_id' => 'package',
            'member_id' => 'member',
            'start_at' => 'start date',
            'start_time' => 'start time',
            'repeat_until' => 'repeat until',
            'end_time' => 'end time',
            'end_at' => 'end date',
            'renewal_of' => 'renewal of',
            'subscription_day' => 'billing day',
            'number_of_peoples' => 'number of people',
            'discount_percentage' => 'discount %',
            'discount_value' => 'discount value',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
