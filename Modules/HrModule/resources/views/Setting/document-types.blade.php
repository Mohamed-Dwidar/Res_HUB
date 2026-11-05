{{-- HR › Document Types (the account only): the kinds of employee documents, their expiry reminder and "required" --}}
@extends('layoutmodule::account.main')

@section('title')
    Document Types
@endsection

@section('actions')
    <a href="{{ route('account.hr.document-types.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Document Type</a>
@endsection

@section('content')
@endsection

@include('layoutmodule::partials.datatable-assets')
