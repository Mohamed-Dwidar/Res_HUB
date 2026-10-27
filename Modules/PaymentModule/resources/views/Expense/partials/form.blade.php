{{--
    The expense fields (add / edit): category, item, amount, method, date, notes, receipt (PDF / image, private).
    $expense, $categories, $methods; $closedShift (edit): an expense of a closed shift needs a reason (logged on the shift).
--}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'expense_category_id', 'label' => 'Category', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $categories, 'value' => $expense->expense_category_id ?: null,
        'hint' => empty($categories) ? 'No categories yet: the account adds them from Settings › Expense Categories.' : null])
    <div class="md:col-span-2">
        @include('layoutmodule::partials.field', ['name' => 'item', 'label' => 'Item', 'required' => true, 'value' => $expense->item, 'attrs' => 'maxlength="255"',
            'hint' => 'What was paid for, e.g. "Electricity bill October".'])
    </div>
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'value' => $expense->amount,
        'attrs' => 'min="0.01" step="0.01" max="99999999"'])
    @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Paid By', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $methods, 'value' => $expense->payment_method_id ?: null])
    {{-- add: the recording date (employees can't change it); edit (the account): the saved date --}}
    @if ($expense->exists)
        @include('layoutmodule::partials.field', ['name' => 'spent_at', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => $expense->spent_at?->format('Y-m-d')])
    @else
        @include('paymentmodule::Payment.partials.date-field', ['name' => 'spent_at', 'value' => $expense->spent_at])
    @endif
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
    @include('paymentmodule::Payment.partials.reference-field', ['value' => $expense->reference])
</div>
<div class="mt-5">
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $expense->notes])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-2">
    <div data-field="attachment">
        <label for="field-attachment" class="{{ config('layoutmodule.form.label') }}">Receipt</label>
        <input type="file" name="attachment" id="field-attachment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="{{ config('layoutmodule.form.input') }} field-long">
        <div class="{{ config('layoutmodule.form.hint') }}">PDF or image, max 5 MB (optional).{{ !empty($hasAttachment) ? ' A new file replaces the saved one.' : '' }}</div>
        @error('attachment')
            <div class="{{ config('layoutmodule.form.error') }}">{{ $message }}</div>
        @enderror
    </div>
    @if (!empty($hasAttachment))
        <div class="md:pt-8">
            @include('layoutmodule::partials.checkbox', ['name' => 'delete_attachment', 'label' => 'Remove the saved receipt', 'checked' => false])
        </div>
    @endif
</div>
@if (!empty($closedShift))
    <div class="mt-5">
        @include('layoutmodule::partials.field', ['name' => 'change_reason', 'label' => 'Reason for the Change', 'type' => 'textarea', 'required' => true,
            'attrs' => 'maxlength="255"', 'hint' => 'This cash out is in a closed shift: the reason is kept in the shift\'s log, its totals are calculated again.'])
    </div>
@endif
