@extends(Auth::user()->userable->layout())

{{--
    Report 5 — Subscriptions (docs/reports-plan.md §5): the period subscriptions by the renewal rules of the Schedule page / the
    reservations list (SubscriptionReport). Page pattern: intro + (i); rows: search + date (starts on this month, presets) + N days |
    space → unit, plan, Clear; totals box; tab buttons with counts above the table (hidden "view", data-filter-keep).
--}}
@section('title')
    Subscriptions
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.subscriptions.excel') }}" data-export="#subscription-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'Monthly / yearly subscriptions: new, renewed, not renewed, ending soon, left.',
        'details' => 'Period subscriptions only (counted, with a member). New = started in the dates, not a renewal. Renewed / Not renewed / Left = by the renewal date (the day after the last day) in the dates; Renewed = the renewal exists and is paid; Not renewed includes "Renewed · unpaid". Ending soon = renewal date from today to N days later, not yet renewed and paid (the dates do not apply). Amount = the period\'s own price.'])

    <form id="subscription-filters" class="report-toolbar mt-5">
        <div class="filter-bar">
            <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Member name or phone" autocomplete="off">
            @include('layoutmodule::partials.date-range-filter', ['initial' => $initial, 'presets' => \Modules\ReportModule\app\Services\ReportDates::presets()])
            <label class="filter-check">Ending within
                <input type="number" name="days" value="{{ $days }}" min="1" max="365" class="{{ config('layoutmodule.form.input') }} report-days" data-filter-keep aria-label="Days"> days
            </label>
        </div>
        <div class="filter-bar">
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
            <select name="plan_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Plan" data-filter-parents="space_id">
                <option value="">All Plans</option>
                @foreach ($plans as $id => $plan)
                    <option value="{{ $id }}" @class(['option-inactive' => !$plan['active']]) data-match="{{ collect($plan['spaces'])->map(fn ($space) => 'space_id:' . $space)->implode(' ') }}">{{ $plan['name'] }}{{ $plan['active'] ? '' : ' (Inactive)' }}</option>
                @endforeach
            </select>
            @include('layoutmodule::partials.datatable-clear')
        </div>

        <div data-html="subscription-totals">@include('reportmodule::Report.partials.subscription-totals', ['totals' => null, 'counts' => [], 'filters' => ['view' => 'new'], 'range' => ''])</div>

        {{-- the tabs: a view choice (data-filter-keep: sent, not counted / reset by Clear); counts from the json "counts" --}}
        <div>
            <input type="hidden" name="view" value="" data-filter-keep>
            <div class="report-segments" role="group" aria-label="Tab">
                @foreach (\Modules\ReportModule\app\Services\SubscriptionReport::TABS as $value => $label)
                    <button type="button" data-filter-tab data-name="view" data-value="{{ $value === 'new' ? '' : $value }}" @class(['active' => $value === 'new'])>
                        {{ $label }} (<span data-counter="subscriptions-{{ $value }}">0</span>)
                    </button>
                @endforeach
            </div>
        </div>
    </form>

    <div class="table-wrap mt-3">
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.subscriptions.data') }}"
            data-filters="#subscription-filters" data-order='[]' data-page-length="25" data-empty="No subscriptions here.">
            <thead>
                <tr>
                    <th data-data="member_html">Member</th>
                    <th data-data="place">Space › Unit</th>
                    <th data-data="plan_name">Plan</th>
                    <th data-data="period" data-class="whitespace-nowrap">Period</th>
                    <th data-data="renewal" data-class="whitespace-nowrap">Renewal Date</th>
                    <th data-data="amount" data-class="text-right whitespace-nowrap">Amount</th>
                    <th data-data="state">State</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
