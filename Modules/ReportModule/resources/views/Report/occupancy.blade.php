@extends(Auth::user()->userable->layout())

{{--
    Report 6 — Performance (was Occupancy, renamed 2026-11-18; docs/reports-plan.md §6): today's active units, booked vs available
    in the period + the revenue of their bookings (OccupancyReport). Page pattern: intro + (i); rows: the period picker (default this
    month, Compare: none; hidden in Over time) [+ Rows] | type, space, unit, Clear; view By unit | Over time (2026-11-18: one row per
    month / quarter / half-year / year, vs the same days last year, + 2 small charts); the report = server html under the toolbar on every change (custom.js "Live report").
--}}
@section('title')
    Performance
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.performance.excel') }}" data-export="#occupancy-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'How much of each unit was used in the period, and the value of its bookings.',
        'details' => 'Revenue = the value of the bookings in the period, not money received (see the Income report): hourly / full day = the reservations starting in the period at their net (after discount, before tax) + their extras, a package reservation at its share of the package net; subscriptions = each period prorated by its days inside the period (a yearly 7,000 gives a 31-day month 31 / 365 of it). Today\'s active units; capacity = the unit\'s concurrent usage. Each booking counts only inside the dates. Hourly: booked hours ÷ (capacity × business hours a day × days). Full day: booked days ÷ (capacity × days). Period (subscriptions): seats taken ÷ capacity — one seat per subscription active in the dates (a renewal counts once with its period), and a period that ended in the 31 days before, not marked left, with no renewal yet still takes its seat ("renewal pending"); older ones are listed, not counted. Avg occupied = seats in use (hourly / full day: on average); Avg free = capacity − that. Free today = capacity − what is taken now / today. Cancelled-like statuses do not count. Over time: the picked unit / space (or all units) per month / quarter / half-year / year of the range, each vs the same days one year earlier (a running period on the same dates).'])

    <form id="occupancy-filters" class="report-toolbar mt-5" data-report-live data-url="{{ route($area . '.reports.performance.data') }}" data-target="[data-occupancy-body]">
        {{-- the view (2026-11-18): By unit (the table of units) | Over time (one row per month / quarter / half-year / year of the picked
             range, vs the same days last year); data-filter-keep = sent, not counted / reset by Clear --}}
        <div>
            <input type="hidden" name="view" value="" data-filter-keep>
            <div class="report-segments" role="group" aria-label="View">
                <button type="button" data-live-view="" class="active">By unit</button>
                <button type="button" data-live-view="time">Over time</button>
            </div>
        </div>
        <div class="filter-bar">
            @include('reportmodule::Report.partials.period-picker', ['typeDefault' => 'month', 'compareDefault' => 'none', 'initial' => $initial, 'presets' => $presets, 'compareShowIf' => 'view='])
            <select name="rows" class="{{ config('layoutmodule.form.select') }}" aria-label="Rows" data-show-if="view=time">
                @foreach (\Modules\ReportModule\app\Services\OccupancyReport::ROWS as $value => $label)
                    <option value="{{ $value === 'month' ? '' : $value }}">Rows: {{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="filter-bar">
            <select name="subscription_type_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Type">
                <option value="">All Types</option>
                @foreach ($types as $id => $name)
                    <option value="{{ $id }}">{{ Str::humanize($name) }}</option>
                @endforeach
            </select>
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
            @include('layoutmodule::partials.datatable-clear')
        </div>
    </form>

    <div class="mt-5" data-occupancy-body>
        <div class="box p-5 text-slate-500">Loading…</div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/vendors/chartjs.js') }}"></script>
@endpush
