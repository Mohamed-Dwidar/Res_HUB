{{-- Add a leave (popup): type, From / To / Days linked (custom.js leaveDates(): one changed → the others follow), notes, one
     optional file. "On leave" is shown automatically while today is inside a leave (no status / login change). --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Add Leave · {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.hr.leaves.store', $staff->id) }}" enctype="multipart/form-data" data-ajax data-leave-form>
        @csrf
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'leave_type_id', 'label' => 'Leave Type', 'type' => 'select', 'required' => true,
                'options' => ['' => '- Choose -'] + $types->mapWithKeys(fn ($type) => [$type->id => $type->name . ($type->is_paid ? '' : ' (unpaid)')])->all(),
                'hint' => $types->where('needs_attachment', true)->isNotEmpty() ? 'Needs a file: ' . $types->where('needs_attachment', true)->pluck('name')->implode(', ') . '.' : null])
            @include('layoutmodule::partials.field', ['name' => 'days', 'label' => 'Days', 'type' => 'number', 'value' => 1, 'attrs' => 'min=0.5 step=0.5',
                'hint' => 'From, To and Days follow each other. Half days allowed (1.5 = 2 calendar days).'])
            @include('layoutmodule::partials.field', ['name' => 'from_date', 'label' => 'From', 'type' => 'date', 'required' => true, 'value' => today()->toDateString()])
            @include('layoutmodule::partials.field', ['name' => 'to_date', 'label' => 'To', 'type' => 'date', 'required' => true, 'value' => today()->toDateString()])
            <div data-field="attachment" class="md:col-span-2">
                <label for="field-attachment" class="{{ config('layoutmodule.form.label') }}">File</label>
                <input type="file" name="attachment" id="field-attachment" accept=".pdf,.jpg,.jpeg,.png" class="{{ config('layoutmodule.form.input') }} field-long">
                <div class="{{ config('layoutmodule.form.hint') }}">Optional (e.g. a sick note): PDF / JPG / PNG. Private: only HR can open it.</div>
            </div>
            @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'wrapperClass' => 'md:col-span-2'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'leaves'])])
    </form>
@endsection
