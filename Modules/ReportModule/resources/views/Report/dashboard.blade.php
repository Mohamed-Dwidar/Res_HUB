@extends(Auth::user()->userable->layout())

{{--
    Financial Dashboard (2026-11-12, DashboardReport; permission reports.dashboard): the period picker (default this month vs the
    previous period) → KPI cards + charts (Chart.js 4.5.1, the template's local copy public/assets/js/vendors/chartjs.js), server html
    on every change (custom.js "Live report"; the charts are drawn by custom.js "Financial Dashboard" from [data-dash-data]).
    Every card / chart opens its report on the same period.
--}}
@section('title')
    Financial Dashboard
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'The money of a period at a glance, compared with another period.',
        'details' => 'Income = the Income report\'s Net (before tax). Expenses = Cash Out without advances (the P&L rule). Net profit = Income − Expenses; margin = Net ÷ Income. Owed by members = the members\' positive balances on the period\'s last day (never after today). A period that is still running is compared up to the same point (1–12 Nov vs 1–12 Oct). Green = better, red = worse.'])

    <form id="dashboard-filters" class="report-toolbar mt-5" data-report-live data-url="{{ route($area . '.reports.dashboard.data') }}" data-target="[data-dashboard-body]">
        <div class="filter-bar">
            @include('reportmodule::Report.partials.period-picker', ['typeDefault' => 'month', 'compareDefault' => 'previous'])
            @include('layoutmodule::partials.datatable-clear')
        </div>
    </form>

    <div class="mt-5" data-dashboard-body>
        <div class="box p-5 text-slate-500">Loading…</div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/vendors/chartjs.js') }}"></script>
@endpush
