@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Add Cash In
@endsection

@section('modal-size', 'lg')

@section('content')
    <form method="POST" action="{{ route($area . '.payments.store') }}" data-ajax data-payment-form
        data-confirm="The cash in will be added. You can edit it until your shift is closed; after that only the account owner can change it. Only the owner can delete it."
        data-confirm-title="Add this cash in?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf

        @include('paymentmodule::Payment.partials.form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.payments.index')])
    </form>
@endsection
