@extends(Auth::user()->userable->layout())

{{--
    Report 2 — Member statement (docs/reports-plan.md §2): one member's charges and payments in date order, running balance.
    Toolbar: member (the report's own search) | date range | "Package reservations" (information lines at 0) | Clear; the
    statement comes from {area}.reports.member-statement.data (json html) on every change (custom.js "Member statement").
    ?member_id= = that member chosen (the Outstanding popup).
--}}
@section('title')
    Member Statement
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.member-statement.pdf') }}" data-export="#statement-filters" data-export-blank data-statement-export hidden class="btn btn-secondary"><i data-lucide="file-text"></i> PDF</a>
    <a href="{{ route($area . '.reports.member-statement.excel') }}" data-export="#statement-filters" data-statement-export hidden class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'One member\'s charges and payments with the running balance.',
        'details' => 'Reservations by their start date, packages, extras and payments (refunds minus). A positive balance = the member owes, negative = credit. Cancelled reservations show at 0. "Package reservations" adds the reservations inside packages as lines at 0.'])

    <form id="statement-filters" class="mt-5" data-member-statement data-url="{{ route($area . '.reports.member-statement.data') }}">
        {{-- one line: Member (the reservation form's field: field partial + Tom Select server search, the report's own search route,
             no quick add) | date | Package reservations | Clear --}}
        <div class="box p-5 filter-bar statement-filters">
            @include('layoutmodule::partials.field', ['name' => 'member_id', 'label' => 'Member', 'type' => 'select', 'searchable' => true,
                'width' => 'long', 'wrapperClass' => 'statement-filters__member',
                'options' => ['' => 'Search by name or mobile'] + ($member ? [$member->id => $member->name . ' ' . $member->phone] : []),
                'value' => $member?->id,
                'attrs' => 'data-member-select data-member-no-add data-search-url="' . route($area . '.reports.member-statement.members') . '"'])
            <div>
                <label class="{{ config('layoutmodule.form.label') }}">Dates</label>
                @include('layoutmodule::partials.date-range-filter', ['presets' => \Modules\ReportModule\app\Services\ReportDates::presets()])
            </div>
            <label class="filter-check">
                <input type="checkbox" name="package_lines" value="1" class="{{ config('layoutmodule.form.checkbox') }}"> Package reservations
            </label>
            @include('layoutmodule::partials.datatable-clear')
        </div>
    </form>

    <div class="mt-5" data-statement-body>
        @include('reportmodule::Report.partials.member-statement-body', ['statement' => null])
    </div>
@endsection
