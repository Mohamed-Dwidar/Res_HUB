@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Edit: {{ $staff->name }}
@endsection

@section('modal-size', 'xl')

@section('content')
    <form method="POST" action="{{ route($area . '.hr.staff.update', $staff->id) }}" enctype="multipart/form-data" data-ajax>
        @csrf
        @method('PUT')
        @include('hrmodule::Staff.partials.form')
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', $staff->id)])
    </form>
@endsection
