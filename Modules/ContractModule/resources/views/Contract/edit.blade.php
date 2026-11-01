@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

@section('title')
    Edit {{ $contract->isRenewal() ? 'Renewal Annex' : 'Contract' }} Draft
@endsection


@section('content')
    <form method="POST" action="{{ route($area . '.contracts.update', $contract->id) }}" data-ajax data-contract-form data-contract-template-form
        data-confirm="The draft and its text are saved (still no number; the template is not changed)."
        data-confirm-title="Save the draft?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('contractmodule::Contract.partials.form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.contracts.show', $contract->id)])
    </form>
@endsection
