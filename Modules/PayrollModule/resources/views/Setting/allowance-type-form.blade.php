@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $allowanceType ? 'Edit Allowance Type' : 'Add Allowance Type' }}
@endsection

@section('content')
    <form method="POST" action="{{ $allowanceType ? route('account.hr.allowance-types.update', $allowanceType->id) : route('account.hr.allowance-types.store') }}" data-ajax>
        @csrf
        @if ($allowanceType) @method('PUT') @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $allowanceType?->name_en, 'attrs' => 'autofocus'])
            @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'value' => $allowanceType?->name_ar, 'attrs' => 'dir=rtl'])
            @include('layoutmodule::partials.checkbox', ['name' => 'is_insurable', 'label' => 'Insurable', 'checked' => $allowanceType?->is_insurable ?? false,
                'hint' => 'Counted in the insurable salary (basic + insurable allowances). Ask the accounting office.'])
            @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $allowanceType?->is_active ?? true,
                'hint' => 'Inactive types are not offered for new salaries; saved salaries keep them.'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.hr.allowance-types.index')])
    </form>
@endsection
