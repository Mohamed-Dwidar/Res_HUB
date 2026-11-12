@extends(Auth::user()->userable->layout())

{{--
    Report 7 — Profit & Loss (simple, cash basis; PnlReport): a column per month of the dates + Total. Income = the Income report's
    Net (before tax) by subscription type; Expenses = Cash Out by category (Salaries first); Net profit; advances below the line.
    Page pattern: intro + (i); the period picker (2026-11-12, ReportPeriod: starts on this year, no comparison; Custom = the date
    field with the P&L presets) + Columns (month / quarter / half-year / year); server html on every change (custom.js "Live report").
--}}
@section('title')
    Profit &amp; Loss
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.pnl.excel') }}" data-export="#pnl-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'Income − expenses, month by month.',
        'details' => 'Cash basis, whole months (at most ' . \Modules\ReportModule\app\Services\PnlReport::MAX_COLUMNS . ' columns). The small % under a column = the change from the column before it; with a comparison, its totals + the change are the last two columns. Income = the Income report\'s Net (before tax): cash in + withholding − refunds of reservations, packages and other income counted as income, by the payment date, without VAT / table tax; by subscription type (package payments = Packages). Expenses = every Cash Out by its date (shift and company), by category; Salaries = what was paid. Net profit = Income − Expenses. Advances are not an expense: given / repaid in cash / deducted from salaries are shown below the line and are not in the profit.'])

    <form id="pnl-filters" class="report-toolbar mt-5" data-report-live data-url="{{ route($area . '.reports.pnl.data') }}" data-target="[data-pnl-body]">
        <div class="filter-bar">
            @include('reportmodule::Report.partials.period-picker', ['typeDefault' => 'year', 'initial' => $initial, 'presets' => $presets])
            <select name="columns" class="{{ config('layoutmodule.form.select') }}" aria-label="Columns">
                @foreach (\Modules\ReportModule\app\Services\PnlReport::COLUMNS as $value => $label)
                    <option value="{{ $value === 'month' ? '' : $value }}">Columns: {{ $label }}</option>
                @endforeach
            </select>
            @include('layoutmodule::partials.datatable-clear')
        </div>
    </form>

    <div class="mt-5" data-pnl-body>
        <div class="box p-5 text-slate-500">Loading…</div>
    </div>
@endsection
