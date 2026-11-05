@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $department ? 'Edit Department' : 'Add Department' }}
@endsection

@section('content')
    <form method="POST" action="{{ $department ? route('account.hr.departments.update', $department->id) : route('account.hr.departments.store') }}" data-ajax>
        @csrf
        @if ($department) @method('PUT') @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $department?->name_en, 'attrs' => 'autofocus'])
            @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'value' => $department?->name_ar, 'attrs' => 'dir=rtl'])
            @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $department?->is_active ?? true, 'hint' => 'Inactive departments are hidden from the staff form.'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.hr.departments.index')])
    </form>
@endsection
