@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $leaveType ? 'Edit Leave Type' : 'Add Leave Type' }}
@endsection

@section('content')
    <form method="POST" action="{{ $leaveType ? route('account.hr.leave-types.update', $leaveType->id) : route('account.hr.leave-types.store') }}" data-ajax>
        @csrf
        @if ($leaveType) @method('PUT') @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $leaveType?->name_en, 'attrs' => 'autofocus'])
            @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'value' => $leaveType?->name_ar, 'attrs' => 'dir=rtl'])
            @include('layoutmodule::partials.field', ['name' => 'yearly_days', 'label' => 'Yearly Days', 'type' => 'number', 'value' => $leaveType?->yearly_days,
                'attrs' => 'min=0 max=365 step=0.5', 'hint' => 'Everyone\'s yearly balance (each person can get their own). Empty = no balance kept (e.g. sick, unpaid).'])
            <div></div>
            @include('layoutmodule::partials.checkbox', ['name' => 'is_paid', 'label' => 'Paid', 'checked' => $leaveType?->is_paid ?? true,
                'hint' => 'Unpaid: payroll deducts its days (salary ÷ days in a month).'])
            @include('layoutmodule::partials.checkbox', ['name' => 'needs_attachment', 'label' => 'Needs a file', 'checked' => $leaveType?->needs_attachment ?? false,
                'hint' => 'A warning when a leave of this type has no file (e.g. a sick note). Never a block.'])
            @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $leaveType?->is_active ?? true])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.hr.leave-types.index')])
    </form>
@endsection
