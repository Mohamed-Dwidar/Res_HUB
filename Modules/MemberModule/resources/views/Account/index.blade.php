@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

@section('title')
    Members
@endsection

@section('actions')
    <a href="{{ route($area . '.members.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Member</a>
@endsection

@section('content')
    <form id="members-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Name, phone, email or national number" autocomplete="off">
        <select name="company_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Company">
            <option value="">All Companies</option>
            @foreach ($companies as $id => $name)
                <option value="{{ $id }}">{{ Str::humanize($name) }}</option>
            @endforeach
        </select>
        <select name="job_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Job">
            <option value="">All Jobs</option>
            @foreach ($jobs as $id => $name)
                <option value="{{ $id }}">{{ Str::humanize($name) }}</option>
            @endforeach
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table id="members-table" class="data-table" data-datatable data-url="{{ route($area . '.members.data') }}"
            data-filters="#members-filters" data-order='[[0, "asc"]]' data-empty="No members yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Name</th>
                    <th data-data="company">Company</th>
                    <th data-data="job">Job</th>
                    <th data-data="reservations_html" data-name="reservations_count">Reservations</th>
                    <th data-data="created_at" data-name="created_at" data-class="whitespace-nowrap">Added</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
