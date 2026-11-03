@extends('layoutmodule::account.main')

@section('title')
    Employees
@endsection

@section('actions')
    <a href="{{ route('account.employees.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Employee</a>
@endsection

@section('content')
    <form id="employees-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Name, phone or email" autocomplete="off">
        <select name="position_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Position">
            <option value="">All Positions</option>
            @include('layoutmodule::partials.filter-options', ['options' => $positions])
        </select>
        <select name="shift_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Shift">
            <option value="">All Shifts</option>
            @include('layoutmodule::partials.filter-options', ['options' => $shifts])
        </select>
        <select name="status" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">All Statuses</option>
            <option value="working">Working</option>
            <option value="left">Left</option>
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route('account.employees.data') }}"
            data-filters="#employees-filters" data-order='[[0, "asc"]]' data-empty="No employees yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Employee</th>
                    <th data-data="phone" data-name="phone" data-class="whitespace-nowrap">Phone</th>
                    <th data-data="position">Position</th>
                    <th data-data="role">Role</th>
                    <th data-data="shift">Shift</th>
                    <th data-data="join_date" data-name="join_date" data-class="whitespace-nowrap">Join Date</th>
                    <th data-data="status">Status</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
