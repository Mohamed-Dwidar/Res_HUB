{{-- Unit create / edit fields. $unit is null on create. --}}
<div class="form-section">Unit Data</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'required' => true, 'value' => $unit?->name_ar, 'attrs' => 'dir="rtl" autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'required' => true, 'value' => $unit?->name_en])
    @include('layoutmodule::partials.field', ['name' => 'subscription_type_id', 'label' => 'Subscription Type', 'type' => 'select',
        'options' => ['' => '- None -'] + $subscriptionTypes, 'value' => $unit?->subscription_type_id ?: null,
        'hint' => empty($subscriptionTypes) ? 'This space has no subscription types, assign them from the space form.' : 'Only the subscription types assigned to this space.'])
    @include('layoutmodule::partials.field', ['name' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => true, 'value' => $unit?->capacity,
        'attrs' => 'min="1" step="1" max="100000"', 'hint' => 'Number of persons.'])
    @include('layoutmodule::partials.field', ['name' => 'concurrent_usage', 'label' => 'Concurrent Usage', 'type' => 'number', 'required' => true, 'value' => $unit?->concurrent_usage ?? 1,
        'attrs' => 'min="1" step="1" max="1000"', 'hint' => 'How many bookings can use the unit at the same time.'])
    @include('layoutmodule::partials.field', ['name' => 'lease_period', 'label' => 'Lease Period', 'type' => 'select',
        'options' => ['' => '- Select -'] + \Modules\UnitModule\app\Models\Unit::LEASE_PERIODS, 'value' => $unit?->lease_period])
    @include('unitmodule::partials.color-select', ['selected' => $unit?->color_id])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $unit?->is_active ?? true])
</div>

<div class="form-section mt-8">Plans</div>
@include('planmodule::Account.partials.checklist', [
    'plans' => $plans, 'selected' => $unit?->plans->pluck('id')->all() ?? [],
    'empty' => 'This space has no plans, assign them from the space form.',
    'hint' => 'Only the plans assigned to this space.',
])

{{-- MessagingModule (2026-10-31): the messages sent for this unit's reservations --}}
@include('messagingmodule::Unit.messages')

{{-- ContractModule (2026-10-31): the contract template of this unit's reservations --}}
@include('contractmodule::Unit.contract')

<div class="form-section mt-8">Description & Notes</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'description_ar', 'label' => 'Description (Arabic)', 'type' => 'textarea', 'value' => $unit?->description_ar, 'attrs' => 'dir="rtl"'])
    @include('layoutmodule::partials.field', ['name' => 'description_en', 'label' => 'Description (English)', 'type' => 'textarea', 'value' => $unit?->description_en])
    @include('layoutmodule::partials.field', ['name' => 'notes_ar', 'label' => 'Notes (Arabic)', 'type' => 'textarea', 'value' => $unit?->notes_ar, 'attrs' => 'dir="rtl"'])
    @include('layoutmodule::partials.field', ['name' => 'notes_en', 'label' => 'Notes (English)', 'type' => 'textarea', 'value' => $unit?->notes_en])
</div>

<div class="form-section mt-8">Images</div>
@include('layoutmodule::partials.images-input', ['images' => $unit?->images, 'alt' => $unit?->name])
