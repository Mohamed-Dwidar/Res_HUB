{{-- Income category create / edit fields. $category is null on create. --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'required' => true, 'value' => $category?->name_ar, 'attrs' => 'dir="rtl" autofocus'])
    @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $category?->name_en, 'hint' => 'e.g. Printing, Drinks, Events.'])
    @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $category?->is_active ?? true, 'hint' => 'Inactive categories are hidden from the payment form.'])
    {{-- 2026-11-08: fills Add Extra (reservations / packages); the price can be changed on each extra --}}
    @include('layoutmodule::partials.field', ['name' => 'default_price', 'label' => 'Default Price', 'type' => 'number', 'value' => $category?->default_price,
        'attrs' => 'min="0" step="0.01" max="99999999"', 'hint' => 'Filled in Add Extra when it is chosen; can be changed on each extra. Empty = typed every time.'])
</div>

{{-- income = every category but the system ones (2026-11-08, no checkbox); no taxes here: extras follow their reservation /
     package (2026-11-08); the old tax flag columns are kept, unused --}}
@if ($category?->isGenerated())
    <div class="mt-5 {{ config('layoutmodule.form.hint') }}">System category: {{ $category->generatedFlag('income') ? 'counted as income, never taxed, can\'t be deleted.' : 'never counted as income.' }}</div>
@endif
