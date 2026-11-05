@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Add Document: {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.hr.staff.documents.store', $staff->id) }}" enctype="multipart/form-data" data-ajax>
        @csrf
        @include('hrmodule::Document.partials.form')
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'documents'])])
    </form>
@endsection
