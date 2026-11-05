@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Add Staff
@endsection

@section('modal-size', 'xl')

@section('content')
    <form method="POST" action="{{ route($area . '.hr.staff.store') }}" enctype="multipart/form-data" data-ajax>
        @csrf
        @include('hrmodule::Staff.partials.form')
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.index')])
    </form>
@endsection
