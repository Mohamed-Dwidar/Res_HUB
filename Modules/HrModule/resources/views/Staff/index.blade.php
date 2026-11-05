{{-- The Staff list (docs/hr-plan.md H1): every staff file (the owner's too); a row opens the staff page. $alerts = the document
     warnings (documents.view only, StaffService::alerts()) --}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

@section('title')
    Staff
@endsection

@section('actions')
    @can('hr.manage')
        <a href="{{ route($area . '.hr.staff.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Staff</a>
    @endcan
@endsection

@section('content')
    @if ($alerts && ($alerts['expired'] || $alerts['expiring'] || $alerts['missing']))
        <div class="notice mb-4 staff-alerts" data-staff-alerts>
            <i data-lucide="alert-triangle"></i>
            <span>Documents:</span>
            @foreach (['expired' => 'expired', 'expiring' => 'expire soon', 'missing' => 'missing'] as $kind => $text)
                @if ($alerts[$kind])
                    <button type="button" class="staff-alerts__link" data-staff-alert="{{ $kind }}">{{ $alerts[$kind] }} {{ $text }}</button>
                @endif
            @endforeach
        </div>
    @endif

    <form id="staff-filters" class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Name, phone or no." autocomplete="off">
        <select name="type" class="{{ config('layoutmodule.form.select') }}" aria-label="Type">
            <option value="">Employees + External</option>
            <option value="staff">Employees (on payroll)</option>
            <option value="external">External</option>
        </select>
        @if ($departments)
            <select name="department_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Department">
                <option value="">All Departments</option>
                @include('layoutmodule::partials.filter-options', ['options' => $departments])
            </select>
        @endif
        <select name="position_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Position">
            <option value="">All Positions</option>
            @include('layoutmodule::partials.filter-options', ['options' => $positions])
        </select>
        <select name="status" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">Current Staff</option>
            @foreach (config('hrmodule.statuses') as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
            <option value="left">Left (resigned / terminated)</option>
            <option value="all">All</option>
        </select>
        @if ($alerts !== null)
            <select name="documents" class="{{ config('layoutmodule.form.select') }}" aria-label="Documents">
                <option value="">All Documents</option>
                <option value="expired">Expired documents</option>
                <option value="expiring">Expiring soon</option>
                <option value="missing">Missing documents</option>
            </select>
        @endif
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route($area . '.hr.staff.data') }}"
            data-filters="#staff-filters" data-order='[]' data-empty="No staff files yet.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name_html">Name</th>
                    <th data-data="work_html" data-orderable="false">Work</th>
                    <th data-data="phone_html" data-orderable="false" data-class="whitespace-nowrap">Phone</th>
                    <th data-data="hire" data-name="hire" data-class="whitespace-nowrap">Hire Date</th>
                    @if ($alerts !== null)
                        <th data-data="documents_html" data-orderable="false">Documents</th>
                    @endif
                    <th data-data="status_html" data-orderable="false">Status</th>
                    <th data-data="actions" data-orderable="false"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
