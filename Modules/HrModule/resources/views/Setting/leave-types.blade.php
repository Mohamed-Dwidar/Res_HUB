{{-- HR › Leave Types (the account only): paid / unpaid, yearly days (the balance), needs a file (docs/hr-plan.md §3.4) --}}
@extends('layoutmodule::account.main')

@section('title')
    Leave Types
@endsection

@section('actions')
    <a href="{{ route('account.hr.leave-types.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Leave Type</a>
@endsection

@section('content')
    <div class="table-wrap">
        <table class="data-table" data-datatable data-url="{{ route('account.hr.leave-types.data') }}" data-order='[]' data-empty="No leave types yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name_html">Name</th>
                    <th data-data="paid" data-orderable="false">Pay</th>
                    <th data-data="days" data-orderable="false">Yearly Days</th>
                    <th data-data="attachment" data-orderable="false">Needs a File</th>
                    <th data-data="leaves_count" data-name="leaves_count">Leaves</th>
                    <th data-data="status" data-orderable="false">Status</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
