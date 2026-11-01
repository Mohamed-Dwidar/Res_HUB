@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

@section('title')
    {{ $draft['kind'] === 'renewal' ? 'Renew Contract' : 'Create Contract' }}
@endsection


@section('content')
    <form method="POST" action="{{ route($area . '.contracts.store') }}" data-ajax data-contract-form data-contract-template-form
        data-confirm="A draft is created with this text (no number yet; the template is not changed). Issue it after checking the PDF."
        data-confirm-title="Create the draft?"
        data-confirm-button="Create"
        data-confirm-variant="success">
        @csrf

        @include('contractmodule::Contract.partials.form', ['contract' => null])

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.reservations.show', $reservation->id)])
    </form>
@endsection
