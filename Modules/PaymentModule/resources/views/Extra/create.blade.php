@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{-- $kind = package | reservation (2026-11-08), $extrable = it --}}
@section('title')
    Add Extra · {{ $kind === 'reservation' ? 'Reservation #' . $extrable->id : $extrable->name }}
@endsection

@section('modal-size', 'lg')

@section('content')
    <form method="POST" action="{{ route($area . '.extras.store') }}" data-ajax data-extra-form
        data-confirm="It will be added to what the {{ $kind }} owes."
        data-confirm-title="Add this to the {{ $kind }}?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf
        <input type="hidden" name="{{ $kind }}_id" value="{{ $extrable->id }}">

        @include('paymentmodule::Extra.partials.form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.' . $kind . 's.show', $extrable->id)])
    </form>
@endsection
