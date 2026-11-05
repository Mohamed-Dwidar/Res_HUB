{{-- HR › Employee Departments (the account only): optional; a job title may belong to one (HR › Employee Positions) --}}
@extends('layoutmodule::account.main')

@section('title')
    Employee Departments
@endsection

@section('actions')
    <a href="{{ route('account.hr.departments.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Department</a>
@endsection

@section('content')
    <div class="table-wrap">
        <table class="data-table" data-datatable data-url="{{ route('account.hr.departments.data') }}" data-order='[[0, "asc"]]'
            data-empty="No departments yet (optional: Reception, Housekeeping, Security …).">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name_html">Name</th>
                    <th data-data="staff_count" data-name="staff_count">Staff</th>
                    <th data-data="status" data-orderable="false">Status</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>

@endsection

@include('layoutmodule::partials.datatable-assets')
