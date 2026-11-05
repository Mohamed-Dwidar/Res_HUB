@extends('layoutmodule::account.main')

@section('title')
    Employee Shifts
@endsection

@section('actions')
    <a href="{{ route('account.employee-shifts.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Shift</a>
@endsection

@section('content')
    <form id="shifts-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Search by name" autocomplete="off">
        <select name="is_active" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">All Statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route('account.employee-shifts.data') }}"
            data-filters="#shifts-filters" data-order='[[0, "asc"]]' data-empty="No shifts yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Name</th>
                    <th data-data="time_range" data-name="start_time" data-class="whitespace-nowrap">Time</th>
                    <th data-data="employees_count" data-name="employees_count">Users</th>
                    <th data-data="status" data-name="is_active">Status</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
