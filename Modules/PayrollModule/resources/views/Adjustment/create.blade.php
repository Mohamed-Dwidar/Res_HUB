{{-- Add a bonus / deduction (popup): type, month, amount OR days (× the daily rate, by payroll), reason --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Bonus / Deduction · {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.payroll.adjustments.store', $staff->id) }}" data-ajax>
        @csrf
        @if (!$types)
            <div class="notice notice--warning mb-4">No bonus / deduction types yet (HR › Adjustment Types).</div>
        @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div data-field="adjustment_type_id">
                <label for="field-adjustment_type_id" class="{{ config('layoutmodule.form.label') }}">Type <span class="text-danger">*</span></label>
                <select name="adjustment_type_id" id="field-adjustment_type_id" class="{{ config('layoutmodule.form.select') }} field-medium" required>
                    <option value="">- Choose -</option>
                    @foreach ($types as $kind => $options)
                        <optgroup label="{{ $kind }}">
                            @foreach ($options as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            @include('layoutmodule::partials.field', ['name' => 'period', 'label' => 'Month', 'type' => 'select', 'required' => true, 'options' => $months, 'value' => today()->startOfMonth()->toDateString()])
            @include('layoutmodule::partials.field', ['name' => 'value_kind', 'label' => 'By', 'type' => 'select', 'options' => ['amount' => 'Amount', 'days' => 'Days of salary']])
            <div data-show-if="value_kind=amount">
                @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'attrs' => 'min=0.01 step=0.01'])
            </div>
            <div data-show-if="value_kind=days">
                @include('layoutmodule::partials.field', ['name' => 'days', 'label' => 'Days', 'type' => 'number', 'attrs' => 'min=0.5 max=31 step=0.5',
                    'hint' => 'Payroll turns it into money: salary ÷ days in a month (HR › Payroll Settings).'])
            </div>
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'required' => true, 'attrs' => 'maxlength="255"', 'wrapperClass' => 'md:col-span-2'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'advances'])])
    </form>
@endsection
