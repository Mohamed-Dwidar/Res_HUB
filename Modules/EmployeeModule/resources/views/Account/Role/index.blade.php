@extends('layoutmodule::account.main')

@section('title')
    Roles
@endsection

@section('actions')
    <a href="{{ route('account.settings.roles.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Role</a>
@endsection

@section('content')
    {{-- docs/roles-plan.md: one role per employee (Employees › Edit); settings, employees, roles, the Access Log and your own My Shift are never in a role --}}
    <p class="page-note">What each employee may do. Give a role to an employee from his Edit form; a new employee gets the default role.</p>

    <form id="roles-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Search by name" autocomplete="off">
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route('account.settings.roles.data') }}"
            data-filters="#roles-filters" data-order='[[0, "asc"]]' data-empty="No roles yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Name</th>
                    <th data-data="permissions_count" data-orderable="false">Permissions</th>
                    <th data-data="idle" data-name="idle_hours">Idle Timeout</th>
                    <th data-data="employees_count" data-name="employees_count">Employees</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
