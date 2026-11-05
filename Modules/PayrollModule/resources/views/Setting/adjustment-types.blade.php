{{-- HR › Adjustment Types (the account only): the bonus / deduction types (docs/hr-plan.md §3.5) --}}
@extends('layoutmodule::account.main')

@section('title')
    Adjustment Types
@endsection

@section('actions')
    <a href="{{ route('account.hr.adjustment-types.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Type</a>
@endsection

@section('content')
    <div class="table-wrap">
        <table class="data-table" data-datatable data-url="{{ route('account.hr.adjustment-types.data') }}" data-order='[[0, "asc"]]'
            data-empty="No types yet (Incentive, Overtime, Penalty, Absence …).">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name_html">Name</th>
                    <th data-data="kind_html" data-orderable="false">Kind</th>
                    <th data-data="adjustments_count" data-name="adjustments_count">Used</th>
                    <th data-data="status" data-orderable="false">Status</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
