@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    New Message
@endsection

@section('modal-size', 'xl')

@section('content')
    <form method="POST" action="{{ route('account.settings.messages.templates.store') }}" data-ajax data-message-template-form
        data-confirm="This message will be offered for the reservations of the chosen units."
        data-confirm-title="Add this message?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf

        @include('messagingmodule::Setting.partials.template-form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.messages.edit')])
    </form>
@endsection
