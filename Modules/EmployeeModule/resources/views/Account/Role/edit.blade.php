@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Edit Role: {{ $role->name }}
@endsection

@section('actions')
    @include('employeemodule::Account.Role.partials.delete-form')
@endsection

{{-- wide: the permissions 3 per row --}}
@section('modal-size', 'xl')

@section('content')
    <form method="POST" action="{{ route('account.settings.roles.update', $role->id) }}" data-ajax data-row="{{ $role->id }}"
        data-confirm="The changes to &quot;{{ $role->name }}&quot; apply to its {{ $role->employees_count }} employee(s) from their next page."
        data-confirm-title="Save the changes?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('employeemodule::Account.Role.partials.form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.roles.index')])
    </form>
@endsection
