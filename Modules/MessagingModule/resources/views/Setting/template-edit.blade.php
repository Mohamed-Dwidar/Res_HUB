@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $template->type_label }} · {{ $template->isDefault() ? 'All spaces' : $template->space?->name }}
@endsection

@section('modal-size', 'xl')

@section('content')
    <form method="POST" action="{{ route('account.settings.messages.templates.update', $template->id) }}" data-ajax data-message-template-form
        data-confirm="The next messages of this type use the new text (the messages already sent are not changed)."
        data-confirm-title="Save this text?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('messagingmodule::Setting.partials.template-form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.messages.edit')])
    </form>
@endsection
