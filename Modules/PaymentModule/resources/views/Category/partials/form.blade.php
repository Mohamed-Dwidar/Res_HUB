{{-- Income category create / edit fields. $category is null on create. --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'required' => true, 'value' => $category?->name_ar, 'attrs' => 'dir="rtl" autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $category?->name_en, 'hint' => 'e.g. Printing, Drinks, Events.'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $category?->is_active ?? true, 'hint' => 'Inactive categories are hidden from the payment form.'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_revenue', 'label' => 'Counts as income', 'checked' => $category?->is_revenue ?? true, 'hint' => 'Off for money collected for others (e.g. VAT): left out of the income reports.'])
</div>

{{-- a category not counted as income (VAT collected ...) is never taxed --}}
@include('paymentmodule::Tax.partials.flags', ['model' => $category, 'what' => 'extras', 'showIf' => 'is_revenue'])
