@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(Auth::user()->userable->layout())

@section('title')
    Reservations
@endsection

@section('actions')
    <a href="{{ route($area . '.reservations.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Add Reservation</a>
@endsection

@section('content')
    {{--
        Toolbar (docs/reservations-list-plan.md, simplified 2026-10-13): tabs Today (default, value '', running today,
        count of the counted ones) | Upcoming | All; under them ONE row of filters, all visible:
        search | date (one field: Today / All synced, Upcoming = "From <tomorrow>"; picked days = running on them, the Today rule) | type | space | unit | payment | status | renewal | Clear.
        Today / Upcoming hide the contract periods unless the type filter is chosen (a contract type shows its rows).
        Not counted reservations (cancelled ...) = muted, in their date place (2026-10-27; were last).
    --}}
    <form id="reservations-filters" class="filter-bar">
        {{-- opened from the Members list (?member_id=): the All tab --}}
        @include('layoutmodule::partials.filter-tabs', ['tabs' => \Modules\ReservationModule\app\Models\Reservation::TABS, 'default' => 'today', 'initial' => $member ? 'all' : null, 'dates' => \Modules\ReservationModule\app\Models\Reservation::tabDates(), 'counts' => $counts, 'counter' => 'reservations-'])
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Member name or phone" autocomplete="off">
        @include('layoutmodule::partials.date-range-filter')
        {{-- ?member_id= (the Members list count): this member only, chosen when the list opens; Clear / "All Members" = everyone --}}
        @if ($member)
            <select name="member_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Member" data-initial="{{ $member->id }}">
                <option value="">All Members</option>
                <option value="{{ $member->id }}">{{ $member->name }}</option>
            </select>
        @endif
        {{-- ?subscription_type_id= (the Schedule page "View all"): chosen when the list opens (datatables.js [data-initial]) --}}
        <select name="subscription_type_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Type" @if (request()->filled('subscription_type_id')) data-initial="{{ (int) request('subscription_type_id') }}" @endif>
            <option value="">All Types</option>
            @foreach ($types as $id => $name)
                <option value="{{ $id }}">{{ Str::humanize($name) }}</option>
            @endforeach
        </select>
        {{-- type -> space -> unit (2026-10-22): the spaces of the chosen type; the units of the chosen space (and type), shown once a
             space is chosen (custom.js select[data-filter-parents]: the options' data-match "field:value" tokens) --}}
        <select name="space_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Space" data-filter-parents="subscription_type_id">
            <option value="">All Spaces</option>
            @foreach ($spaces as $id => $space)
                <option value="{{ $id }}" @class(['option-inactive' => !$space['active']]) data-match="{{ collect($space['types'])->map(fn ($type) => 'subscription_type_id:' . $type)->implode(' ') }}">{{ Str::humanize($space['name']) }}{{ $space['active'] ? '' : ' (Inactive)' }}</option>
            @endforeach
        </select>
        <select name="unit_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Unit" data-filter-parents="subscription_type_id space_id" data-filter-requires="space_id" hidden>
            <option value="">All Units</option>
            @foreach ($units as $id => $unit)
                <option value="{{ $id }}" @class(['option-inactive' => !$unit['active']]) data-match="space_id:{{ $unit['space_id'] }} subscription_type_id:{{ $unit['subscription_type_id'] }}">{{ $unit['name'] }}{{ $unit['active'] ? '' : ' (Inactive)' }}</option>
            @endforeach
        </select>
        @include('paymentmodule::Payment.partials.status-filter', ['statuses' => \Modules\ReservationModule\app\Models\Reservation::PAYMENT_STATUSES])
        <select name="reservation_status_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Status">
            <option value="">All Statuses</option>
            @include('layoutmodule::partials.filter-options', ['options' => $statuses])
        </select>
        {{-- Renewal (2026-10-23): period rows by RENEWAL date (the date field = renewal dates, else this month; the tabs are
             not used, custom.js moves to All), + the Renewal column and the line below (ReservationActions::renewalFilter()) --}}
        <select name="renewal" class="{{ config('layoutmodule.form.select') }}" aria-label="Renewal">
            <option value="">All Renewals</option>
            @foreach (\Modules\ReservationModule\app\Models\Reservation::RENEWAL_FILTERS as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>
    {{-- the Renewal filter's line (json html "reservations-renewal"; empty without the filter) --}}
    <div data-html="reservations-renewal"></div>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route($area . '.reservations.data') }}"
            data-filters="#reservations-filters" data-order='[]' data-empty="No reservations here.">
            <thead>
                <tr>
                    <th data-data="member_html">Member</th>
                    <th data-data="place">Space &rsaquo; Unit</th>
                    <th data-data="plan">Plan</th>
                    <th data-data="period" data-name="start_at" data-class="whitespace-nowrap">Period</th>
                    {{-- only with the Renewal filter (datatables.js data-visible-if) --}}
                    <th data-data="renewal_html" data-visible-if="renewal">Renewal</th>
                    <th data-data="net_amount" data-name="net_amount" data-class="whitespace-nowrap">Amount</th>
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
