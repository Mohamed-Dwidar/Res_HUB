@extends(Auth::user()->userable->layout())

{{--
    Report 6 — Occupancy (docs/reports-plan.md §6): today's active units, booked vs available in the dates (OccupancyReport).
    Page pattern: intro + (i); rows: the date field (starts on this month, presets inside, no All) | type, space, Clear; the report
    = server html under the toolbar on every change (custom.js "Live report": form[data-report-live]).
--}}
@section('title')
    Occupancy
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.occupancy.excel') }}" data-export="#occupancy-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'How much of each unit was used in the dates.',
        'details' => 'Today\'s active units; capacity = the unit\'s concurrent usage. Each booking counts only inside the dates. Hourly: booked hours ÷ (capacity × business hours a day × days). Full day: booked days ÷ (capacity × days). Period (subscriptions): seats taken ÷ capacity — one seat per subscription active in the dates (a renewal counts once with its period), and a period that ended in the 31 days before, not marked left, with no renewal yet still takes its seat ("renewal pending"); older ones are listed, not counted. Avg occupied = seats in use (hourly / full day: on average); Avg free = capacity − that. Free today = capacity − what is taken now / today. Cancelled-like statuses do not count.'])

    <form id="occupancy-filters" class="report-toolbar mt-5" data-report-live data-url="{{ route($area . '.reports.occupancy.data') }}" data-target="[data-occupancy-body]">
        <div class="filter-bar">
            @include('layoutmodule::partials.date-range-filter', ['initial' => $initial, 'presets' => $presets])
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
            @include('layoutmodule::partials.datatable-clear')
        </div>
    </form>

    <div class="mt-5" data-occupancy-body>
        <div class="box p-5 text-slate-500">Loading…</div>
    </div>
@endsection
