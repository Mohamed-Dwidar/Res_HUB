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
    <div class="text-slate-500">A member's reservations (by their start date), packages, extras and payments with the running balance. A positive balance = the member owes, negative = credit. Cancelled reservations show at 0.</div>

    <form id="statement-filters" class="filter-bar mt-5" data-member-statement data-url="{{ route($area . '.reports.member-statement.data') }}">
        <div class="statement-member">
            <select name="member_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Member" data-member-select data-member-no-add
                data-search-url="{{ route($area . '.reports.member-statement.members') }}">
                <option value="">Search a member (name or mobile)</option>
                @if ($member)
                    <option value="{{ $member->id }}" selected>{{ $member->name }} {{ $member->phone }}</option>
                @endif
            </select>
        </div>
        @include('layoutmodule::partials.date-range-filter')
        <label class="filter-check">
            <input type="checkbox" name="package_lines" value="1" class="{{ config('layoutmodule.form.checkbox') }}"> Package reservations
        </label>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="mt-5" data-statement-body>
        @include('reportmodule::Report.partials.member-statement-body', ['statement' => null])
    </div>
@endsection
