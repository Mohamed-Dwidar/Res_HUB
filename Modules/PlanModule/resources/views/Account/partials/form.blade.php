{{-- Plan create / edit fields. $plan is null on create. The spaces / units choose their plans from their own forms. --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $plan?->name, 'attrs' => 'autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'value' => $plan?->amount,
        'attrs' => 'min="0" step="0.01" max="99999999"'])
    @include('layoutmodule::partials.field', ['name' => 'lease_period', 'label' => 'Lease Period', 'type' => 'select', 'required' => true,
        'options' => ['' => '- Select -'] + \Modules\PlanModule\app\Models\Plan::LEASE_PERIODS, 'value' => $plan?->lease_period])
    @include('layoutmodule::partials.field', ['name' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'value' => $plan?->capacity,
        'attrs' => 'min="1" step="1" max="100000"', 'hint' => 'Number of persons (optional).'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_time_based', 'label' => 'Time based', 'checked' => $plan?->is_time_based ?? false,
        'hint' => 'The reservations choose a start / end time, not only the dates (e.g. hourly plans).'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $plan?->is_active ?? true])
</div>

<div class="mt-5">
    @include('layoutmodule::partials.field', ['name' => 'facilities', 'label' => 'Facilities', 'type' => 'textarea', 'value' => $plan?->facilities,
        'hint' => 'What the plan includes, e.g. Wi-Fi, coffee, meeting room hours.'])
</div>

@include('paymentmodule::Tax.partials.flags', ['model' => $plan, 'what' => 'reservations'])
