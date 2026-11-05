@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $documentType ? 'Edit Document Type' : 'Add Document Type' }}
@endsection

@section('content')
    <form method="POST" action="{{ $documentType ? route('account.hr.document-types.update', $documentType->id) : route('account.hr.document-types.store') }}" data-ajax>
        @csrf
        @if ($documentType) @method('PUT') @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'name_en', 'label' => 'Name (English)', 'value' => $documentType?->name_en, 'attrs' => 'autofocus'])
            @include('layoutmodule::partials.field', ['name' => 'name_ar', 'label' => 'Name (Arabic)', 'value' => $documentType?->name_ar, 'attrs' => 'dir=rtl'])
            @include('layoutmodule::partials.checkbox', ['name' => 'has_expiry', 'label' => 'Has an expiry date', 'checked' => $documentType?->has_expiry ?? false])
            <div data-show-if="has_expiry">
                @include('layoutmodule::partials.field', ['name' => 'remind_days', 'label' => 'Warn before (days)', 'type' => 'number',
                    'value' => $documentType?->remind_days ?? 30, 'attrs' => 'min="1" max="365"'])
            </div>
            @include('layoutmodule::partials.checkbox', ['name' => 'is_required', 'label' => 'Required for every staff file', 'checked' => $documentType?->is_required ?? false,
                'hint' => 'A file without it shows "missing" (a warning only).'])
            @include('layoutmodule::partials.checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => $documentType?->is_active ?? true])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.hr.document-types.index')])
    </form>
@endsection
