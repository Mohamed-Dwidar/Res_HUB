{{-- The social insurance registration (popup). $insurable = the insurable salary in force today (a hint for the insured wage) --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Social Insurance · {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.payroll.insurance.update', $staff->id) }}" data-ajax>
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="md:col-span-2">
                @include('layoutmodule::partials.checkbox', ['name' => 'is_insured', 'label' => 'Insured', 'checked' => $insurance->is_insured])
            </div>
            <div data-show-if="is_insured" class="md:col-span-2"><div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                @include('layoutmodule::partials.field', ['name' => 'insurance_no', 'label' => 'Insurance No.', 'value' => $insurance->insurance_no, 'attrs' => 'maxlength="50"'])
                @include('layoutmodule::partials.field', ['name' => 'start_date', 'label' => 'Start Date', 'type' => 'date', 'value' => $insurance->start_date?->format('Y-m-d')])
                @include('layoutmodule::partials.field', ['name' => 'insured_wage', 'label' => 'Insured Wage', 'type' => 'number', 'value' => $insurance->insured_wage,
                    'attrs' => 'min=0 step=0.01', 'hint' => $insurable !== null ? 'Insurable salary today: ' . number_format($insurable, 2) . '.' : null])
                @include('layoutmodule::partials.field', ['name' => 'end_date', 'label' => 'End Date', 'type' => 'date', 'value' => $insurance->end_date?->format('Y-m-d'),
                    'hint' => 'When the insurance stopped (left / form 6).'])
            </div></div>
            @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $insurance->notes, 'wrapperClass' => 'md:col-span-2'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'salary'])])
    </form>
@endsection
