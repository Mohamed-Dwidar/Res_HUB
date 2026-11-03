@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

@section('title')
    Packages
@endsection

@section('actions')
    @can('packages.create')
        <a href="{{ route($area . '.packages.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Package</a>
    @endcan
@endsection

@section('content')
    {{--
        Packages list (docs/reservations-list-plan.md, packages list 2026-10-13), the reservations list style:
        tabs Current (default, value '': its period includes today or later) | Past | All; one row of filters, all visible:
        search (package, member name or phone) | date (one field: All synced, Current = "From <today>", Past = "Until <yesterday>";
        picked days = the period overlaps them, the All tab) | payment | Clear (no active / inactive since 2026-10-21).
    --}}
    <form id="packages-filters" class="filter-bar">
        @include('layoutmodule::partials.filter-tabs', ['tabs' => \Modules\PackageModule\app\Models\Package::TABS, 'default' => 'current', 'dates' => \Modules\PackageModule\app\Models\Package::tabDates()])
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Package, member name or phone" autocomplete="off">
        @include('layoutmodule::partials.date-range-filter')
        {{-- a package is always paid on its own: no "In package" --}}
        @include('paymentmodule::Payment.partials.status-filter', ['statuses' => \Illuminate\Support\Arr::except(\Modules\PackageModule\app\Models\Package::PAYMENT_STATUSES, 'elsewhere')])
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route($area . '.packages.data') }}"
            data-filters="#packages-filters" data-order='[[1, "desc"]]' data-empty="No packages here.">
            <thead>
                <tr>
                    <th data-data="name_html" data-name="name">Package</th>
                    <th data-data="period" data-name="date_from" data-class="whitespace-nowrap">Period</th>
                    <th data-data="reservations" data-name="counted_reservations" data-class="whitespace-nowrap">Reservations</th>
                    <th data-data="after_discount" data-name="after_discount" data-class="whitespace-nowrap">Amount</th>
                    <th data-data="paid_amount" data-name="paid_amount" data-class="whitespace-nowrap">Paid</th>
                    <th data-data="remaining_html" data-name="remaining" data-class="whitespace-nowrap">Remaining</th>
                    <th data-data="by">Created By</th>
                    <th data-data="status">Status</th>
                    <th data-data="actions"></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
