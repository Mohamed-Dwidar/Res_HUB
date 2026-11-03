@extends('layoutmodule::account.main')

@section('title')
    Plans
@endsection

@section('actions')
    <a href="{{ route('account.plans.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Plan</a>
@endsection

@section('content')
    <form id="plans-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Search by name" autocomplete="off">
        <select name="space_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Space">
            <option value="">All Spaces</option>
            @include('layoutmodule::partials.filter-options', ['options' => $spaces])
        </select>
        <select name="is_active" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">All Statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route('account.plans.data') }}"
            data-filters="#plans-filters" data-order='[[0, "asc"]]' data-empty="No plans yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Name</th>
                    <th data-data="lease_period_label" data-name="lease_period">Lease Period</th>
                    <th data-data="capacity" data-name="capacity">Capacity</th>
                    <th data-data="amount" data-name="amount">Amount</th>
                    <th data-data="spaces_count" data-name="spaces_count">Spaces</th>
                    <th data-data="units_count" data-name="units_count">Units</th>
                    @if ($taxesOn)
                        <th data-data="taxes" data-orderable="false">Taxes</th>
                    @endif
                    <th data-data="status" data-name="is_active">Status</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
