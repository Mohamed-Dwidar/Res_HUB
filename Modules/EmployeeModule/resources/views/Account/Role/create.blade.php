@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Add Role
@endsection

{{-- wide: the permissions 3 per row --}}
@section('modal-size', 'xl')

@section('content')
    {{-- start from a preset (?preset=) or a copy of a role (?from=, the row menu "Duplicate") --}}
    @if ($preset)
        <div class="role-presets">
            Start from:
            @foreach ($presets as $key => $item)
                <a href="{{ route('account.settings.roles.create', ['preset' => $key]) }}" data-modal @class(['active' => $preset === $key])>{{ $item['name'] }}</a>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('account.settings.roles.store') }}" data-ajax
        data-confirm="The role will be added; it changes nothing until you give it to an employee."
        data-confirm-title="Add this role?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf

        @include('employeemodule::Account.Role.partials.form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.roles.index')])
    </form>
@endsection
