{{-- Payment method create / edit fields. $method is null on create. --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'required' => true, 'value' => $method?->name_ar, 'attrs' => 'dir="rtl" autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $method?->name_en, 'hint' => 'e.g. Cash, Visa, InstaPay.'])
    @include('layoutmodule::partials.field', ['name' => 'sort_order', 'label' => 'Sort Order', 'type' => 'number', 'value' => $method?->sort_order,
        'attrs' => 'min="0" max="65535" step="1"', 'hint' => $method ? 'Lower first in the lists.' : 'Lower first in the lists. Empty = after the last one.'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $method?->is_active ?? true, 'hint' => 'Inactive methods are hidden from the payment form.'])
    @include('layoutmodule::partials.checkbox', ['name' => 'requires_reference', 'label' => 'Needs Reference No.', 'checked' => $method?->requires_reference ?? true,
        'hint' => 'Every method but cash: a cash in / refund / cash out with it needs its reference no. (transfer / transaction no.), never used twice.'])
</div>
