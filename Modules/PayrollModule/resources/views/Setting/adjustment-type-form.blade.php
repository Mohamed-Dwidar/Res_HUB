@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $adjustmentType ? 'Edit Adjustment Type' : 'Add Adjustment Type' }}
@endsection

@section('content')
    <form method="POST" action="{{ $adjustmentType ? route('account.hr.adjustment-types.update', $adjustmentType->id) : route('account.hr.adjustment-types.store') }}" data-ajax>
        @csrf
        @if ($adjustmentType) @method('PUT') @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $adjustmentType?->name_en, 'attrs' => 'autofocus'])
            @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'value' => $adjustmentType?->name_ar, 'attrs' => 'dir=rtl'])
            @include('layoutmodule::partials.field', ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'required' => true,
                'options' => \Modules\PayrollModule\app\Models\AdjustmentType::KINDS, 'value' => $adjustmentType?->kind ?? 'bonus', 'hint' => 'Bonus adds to the salary, deduction takes from it.'])
            @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $adjustmentType?->is_active ?? true])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.hr.adjustment-types.index')])
    </form>
@endsection
