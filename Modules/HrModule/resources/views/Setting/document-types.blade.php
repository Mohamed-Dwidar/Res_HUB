{{-- HR › Document Types (the account only): the kinds of employee documents, their expiry reminder and "required" --}}
@extends('layoutmodule::account.main')

@section('title')
    Document Types
@endsection

@section('actions')
    <a href="{{ route('account.hr.document-types.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Document Type</a>
@endsection

@section('content')
    {{-- no order sent = the types' sort order (HrSettingAccountModuleController::typesData) --}}
    <div class="table-wrap">
        <table class="data-table" data-datatable data-url="{{ route('account.hr.document-types.data') }}" data-order='[]'
            data-empty="No document types yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name_html">Name</th>
                    <th data-data="expiry" data-orderable="false">Expiry</th>
                    <th data-data="required" data-orderable="false">Required</th>
                    <th data-data="documents_count" data-name="documents_count">Documents</th>
                    <th data-data="status" data-orderable="false">Status</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
