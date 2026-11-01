@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

{{--
    Contracts (ContractModule Phase 2; account: Management, employees: Daily). Made by hand from a reservation (its view / its row
    menu: Create / Renew Contract), so no "Add" button here. Filters: search | date (the term overlaps) | space → unit | status |
    ending (issued / signed ending in the next N days | ended and not renewed) | Clear.
--}}
@section('title')
    Contracts
@endsection

@section('content')
    <form id="contracts-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="No., member, phone or company" autocomplete="off">
        @include('layoutmodule::partials.date-range-filter')
        <select name="space_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Space">
            <option value="">All Spaces</option>
            @foreach ($spaces as $id => $space)
                <option value="{{ $id }}" @class(['option-inactive' => !$space['active']])>{{ Str::humanize($space['name']) }}{{ $space['active'] ? '' : ' (Inactive)' }}</option>
            @endforeach
        </select>
        <select name="unit_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Unit" data-filter-parents="space_id" data-filter-requires="space_id" hidden>
            <option value="">All Units</option>
            @foreach ($units as $id => $unit)
                <option value="{{ $id }}" @class(['option-inactive' => !$unit['active']]) data-match="space_id:{{ $unit['space_id'] }}">{{ $unit['name'] }}{{ $unit['active'] ? '' : ' (Inactive)' }}</option>
            @endforeach
        </select>
        <select name="status" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">All Statuses</option>
            @foreach (\Modules\ContractModule\app\Models\Contract::STATUSES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select name="ending" class="{{ config('layoutmodule.form.select') }}" aria-label="Ending">
            <option value="">Any End</option>
            <option value="soon">Ending in {{ $endingDays }} days</option>
            <option value="ended">Ended, not renewed</option>
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table id="contracts-table" class="data-table" data-datatable data-url="{{ route($area . '.contracts.data') }}"
            data-filters="#contracts-filters" data-order='[]' data-empty="No contracts yet. Create one from a reservation (its view or its menu).">
            <thead>
                <tr>
                    <th data-data="number_html" data-name="contract_no">No.</th>
                    <th data-data="tenant_html" data-orderable="false">Tenant</th>
                    <th data-data="place" data-orderable="false">Space › Unit</th>
                    <th data-data="term_html" data-name="ends_on">Term</th>
                    <th data-data="status_html" data-name="status">Status</th>
                    <th data-data="by" data-orderable="false">Created By</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
