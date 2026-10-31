@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Add Company
@endsection

@section('modal-size', 'lg')

@section('content')
    <form method="POST" action="{{ route('account.companies.store') }}" data-ajax
        data-confirm="The company will be added to your companies list."
        data-confirm-title="Add this company?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf

        @include('companymodule::Account.partials.form', ['company' => null])

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.companies.index')])
    </form>
@endsection
