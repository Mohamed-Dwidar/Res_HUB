@extends('layoutmodule::account.main')

@section('title')
    New Contract Template @if (!empty($copyOf))<span class="text-slate-500">· copy of {{ $copyOf }}</span>@endif
@endsection

@section('content')
    <form method="POST" action="{{ route('account.settings.contracts.templates.store') }}" data-ajax data-contract-template-form
        data-confirm="{{ !empty($copyOf) ? 'A new template is added; "' . $copyOf . '" is not changed. ' : '' }}Contracts of the chosen units will use this text."
        data-confirm-title="Add this template?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf

        @include('contractmodule::Setting.partials.template-form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.settings.contracts.edit')])
    </form>
@endsection
