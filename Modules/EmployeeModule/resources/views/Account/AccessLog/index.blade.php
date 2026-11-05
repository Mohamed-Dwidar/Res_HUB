@extends('layoutmodule::account.main')

@section('title')
    Access Log
@endsection

@section('content')
    <div class="text-slate-500">Your users' logins, failed logins, logouts, idle timeouts, exports and sensitive actions, newest first.</div>

    <form id="access-log-filters" class="filter-bar mt-5">
        @include('layoutmodule::partials.date-range-filter')
        <select name="user_id" class="{{ config('layoutmodule.form.select') }}" aria-label="User" @if (request()->filled('user_id')) data-initial="{{ (int) request('user_id') }}" @endif>
            <option value="">All Users</option>
            @foreach ($logins as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <select name="event" class="{{ config('layoutmodule.form.select') }}" aria-label="Event">
            <option value="">All Events</option>
            @foreach ($events as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route('account.access-log.data') }}"
            data-filters="#access-log-filters" data-order='[[0, "desc"]]' data-empty="Nothing logged yet.">
            <thead>
                <tr>
                    <th data-data="created_at" data-name="id" data-class="whitespace-nowrap">When</th>
                    <th data-data="who" data-orderable="false">Who</th>
                    <th data-data="event_html" data-name="event">Event</th>
                    <th data-data="details" data-orderable="false">Details</th>
                    <th data-data="client" data-orderable="false">IP / Browser</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
