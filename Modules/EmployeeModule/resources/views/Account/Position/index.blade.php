@extends('layoutmodule::account.main')

@section('title')
    Employee Positions
@endsection

@section('actions')
    <a href="{{ route('account.employee-positions.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Position</a>
@endsection

@section('content')
    <form id="positions-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Search by name" autocomplete="off">
        <select name="is_active" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">All Statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route('account.employee-positions.data') }}"
            data-filters="#positions-filters" data-order='[[0, "asc"]]' data-empty="No positions yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Name</th>
                    @if ($departments)
                        <th data-data="department" data-orderable="false">Department</th>
                    @endif
                    <th data-data="employees_count" data-name="employees_count">Users</th>
                    <th data-data="status" data-name="is_active">Status</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
