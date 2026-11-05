{{-- HR › Allowance Types (the account only): the allowances a salary may have (transport, meals, phone …), docs/hr-plan.md §3.3 --}}
@extends('layoutmodule::account.main')

@section('title')
    Allowance Types
@endsection

@section('actions')
    <a href="{{ route('account.hr.allowance-types.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Allowance Type</a>
@endsection

@section('content')
    <div class="table-wrap">
        <table class="data-table" data-datatable data-url="{{ route('account.hr.allowance-types.data') }}" data-order='[[0, "asc"]]'
            data-empty="No allowance types yet (Transport, Meals, Phone …).">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name_html">Name</th>
                    <th data-data="insurable" data-orderable="false">Insurable</th>
                    <th data-data="items_count" data-name="items_count">Salaries</th>
                    <th data-data="status" data-orderable="false">Status</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
