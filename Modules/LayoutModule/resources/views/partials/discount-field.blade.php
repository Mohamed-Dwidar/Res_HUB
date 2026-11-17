{{--
    ONE "Discount" field (2026-11-17, user): the Discount Type select and the amount on the same line — Percentage (%) shows
    discount_percentage, Fixed amount shows discount_fixed (data-show-if; the other is ignored by the server). Reservation form + Edit Package.
    @include('layoutmodule::partials.discount-field', ['type' => 'percentage', 'percentage' => 10, 'fixed' => 0, 'locked' => false, 'calc' => false])
    $calc = the package form's data-calc attributes (custom.js packageCalculate()); $locked = read only (an employee's locked renewal).
--}}
@php
    $locked = $locked ?? false;
    $calc = $calc ?? false;
@endphp
<div class="discount-field">
    <label for="field-discount_type" class="{{ config('layoutmodule.form.label') }}">Discount</label>
    <div class="discount-line">
        <div data-field="discount_type" class="discount-line__type">
            <select name="discount_type" id="field-discount_type" class="{{ config('layoutmodule.form.select') }}"
                @if ($calc) data-calc="type" @endif @if ($locked) disabled @endif>
                <option value="percentage" @selected($type !== 'fixed')>%</option>
                <option value="fixed" @selected($type === 'fixed')>Fixed amount</option>
            </select>
        </div>
        <div data-field="discount_percentage" data-show-if="discount_type=percentage" class="discount-line__value">
            <input type="number" name="discount_percentage" value="{{ $percentage ?? 0 }}" min="0" max="100" step="0.01" placeholder="%"
                class="{{ config('layoutmodule.form.input') }}" @if ($calc) data-calc="discount" @endif @if ($locked) readonly @endif>
        </div>
        <div data-field="discount_fixed" data-show-if="discount_type=fixed" class="discount-line__value">
            <input type="number" name="discount_fixed" value="{{ $fixed ?? 0 }}" min="0" step="0.01" max="99999999" placeholder="0.00"
                class="{{ config('layoutmodule.form.input') }}" @if ($calc) data-calc="fixed" @endif @if ($locked) readonly @endif>
        </div>
    </div>
</div>
