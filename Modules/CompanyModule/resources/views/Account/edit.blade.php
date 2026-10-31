@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Edit Company: {{ $company->name }}
@endsection

@section('modal-size', 'lg')

@section('content')
    <form method="POST" action="{{ route('account.companies.update', $company->id) }}" data-ajax data-row="{{ $company->id }}"
        data-confirm="The changes to &quot;{{ $company->name }}&quot; will be saved."
        data-confirm-title="Save the changes?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('companymodule::Account.partials.form', ['company' => $company])

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.companies.show', $company->id)])
    </form>
@endsection
