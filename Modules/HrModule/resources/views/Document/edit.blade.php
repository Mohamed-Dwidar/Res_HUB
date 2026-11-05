@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Edit Document: {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.hr.staff.documents.update', [$staff->id, $document->id]) }}" enctype="multipart/form-data" data-ajax>
        @csrf
        @method('PUT')
        @include('hrmodule::Document.partials.form')
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'documents'])])
    </form>
@endsection
