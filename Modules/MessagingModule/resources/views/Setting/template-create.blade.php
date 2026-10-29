@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Add Space Version
@endsection

@section('modal-size', 'xl')

@section('content')
    <form method="POST" action="{{ route('account.settings.messages.templates.store') }}" data-ajax data-message-template-form
        data-confirm="This text will be sent instead of the default for the chosen space's reservations."
        data-confirm-title="Add this space version?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf

        @include('messagingmodule::Setting.partials.template-form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.messages.edit')])
    </form>
@endsection
