@extends('layoutmodule::account.main')

@section('title')
    {{ $template->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route('account.settings.contracts.templates.update', $template->id) }}" data-ajax data-contract-template-form
        data-confirm="The next contracts use the new text and units (contracts already issued are not changed)."
        data-confirm-title="Save this template?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('contractmodule::Setting.partials.template-form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.contracts.edit')])
    </form>
@endsection
