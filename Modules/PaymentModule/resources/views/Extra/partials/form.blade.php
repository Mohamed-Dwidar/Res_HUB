{{--
    Extra / adjustment fields of a package / a reservation. $extra (new on create), $kind (package | reservation), $categories.
    A reservation (2026-11-08): extras only (no Type); the Income Category list = the extra items, choosing one fills the Unit
    Price with its default price and an empty Item with its name (custom.js extraItemDefaults(); both stay editable).
    extra = a sale (income category, revenue); adjustment = a balance move (no category, can be negative).
    The amount = quantity × unit price (the server calculates it). An adjustment (2026-10-21): Date, Item, Amount, Notes
    (no quantity / unit price: quantity 1, ExtraRequest copies adjustment_amount into unit_price).
--}}
@php($employee = request()->routeIs('employee.*'))
@php($salesOnly = $employee || ($kind ?? 'package') === 'reservation')
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    {{-- employees / a reservation: an extra (a sale) only, no adjustment (ExtraRequest, ExtraService) --}}
    @if ($salesOnly)
        <input type="hidden" name="type" value="extra">
    @else
        @include('layoutmodule::partials.field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true,
            'options' => \Modules\PaymentModule\app\Models\Extra::TYPES, 'value' => $extra->type,
            'hint' => 'Extra = a sale (revenue). Adjustment = a balance move (carry-over), not revenue.'])
    @endif
    <div data-show-if="type=extra">
        @include('layoutmodule::partials.field', ['name' => 'income_category_id', 'label' => 'Income Category', 'type' => 'select', 'required' => true, 'searchable' => true,
            'options' => ['' => '- Select -'] + $categories, 'value' => $extra->income_category_id ?: null,
            'attrs' => 'data-extra-item',
            'hint' => ($kind ?? 'package') === 'reservation' && !$categories ? 'No extra items yet: tick "Extra item" on an income category (Settings).' : null])
    </div>
    @include('layoutmodule::partials.field', ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => $extra->date?->format('Y-m-d')])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'item', 'label' => 'Item', 'required' => true, 'value' => $extra->item, 'attrs' => 'maxlength="255"',
        'hint' => 'e.g. Copies, Buffet, Carry-over from last month.'])
    {{-- an extra: quantity (1 by default) × unit price --}}
    <div data-show-if="type=extra">
        @include('layoutmodule::partials.field', ['name' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true, 'value' => $extra->quantity ?: 1,
            'attrs' => 'min="0.01" step="0.01" max="99999"'])
    </div>
    <div data-show-if="type=extra">
        @include('layoutmodule::partials.field', ['name' => 'unit_price', 'label' => 'Unit Price', 'type' => 'number', 'required' => true,
            'value' => $extra->type === 'extra' ? $extra->unit_price : null,
            'attrs' => $employee ? 'step="0.01" min="0.01" max="99999999"' : 'step="0.01" min="-99999999" max="99999999"',
            'hint' => $employee ? null : 'Negative for a return / a credit.'])
    </div>
    {{-- an adjustment: the amount only (quantity 1) --}}
    @unless ($salesOnly)
        <div data-show-if="type=adjustment">
            @include('layoutmodule::partials.field', ['name' => 'adjustment_amount', 'label' => 'Amount', 'type' => 'number', 'required' => true,
                'value' => $extra->type === 'adjustment' ? $extra->amount : null, 'attrs' => 'step="0.01" min="-99999999" max="99999999"',
                'hint' => 'Negative to lower what the package owes.'])
        </div>
    @endunless
</div>
<div class="mt-5">
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $extra->notes])
</div>
