{{-- A draft line's income tax (typed; a calculator later) + notes (popup); saving recalculates the line --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Tax &amp; Notes · {{ $line->name }} · {{ $run->period->format('F Y') }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.payroll.runs.line.update', [$run->id, $line->id]) }}" data-ajax>
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'income_tax', 'label' => 'Income Tax', 'type' => 'number', 'required' => true,
                'value' => $line->income_tax, 'attrs' => 'min=0 step=0.01 autofocus', 'hint' => 'From the accounting office (0 = none). Kept when the month is recalculated.'])
            @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $line->notes, 'wrapperClass' => 'md:col-span-2'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.payroll.runs.index', ['month' => $run->period->format('Y-m')])])
    </form>
@endsection
