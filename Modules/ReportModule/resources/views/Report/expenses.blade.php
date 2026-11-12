@extends(Auth::user()->userable->layout())

{{--
    Report 4 — Expenses (docs/reports-plan.md §4): Cash Out by date in two blocks, Shift Cash Out and Company Cash Out (advances,
    salaries). Page pattern (reports-plan / progress): intro + (i); rows: search + date (starts on this month, presets inside) |
    category, method, Shift / Company, Clear; totals box (2026-11-12: the period picker + Compare with: the comparison's amount +
    Change in By Category, changes in the totals box; Details = the current period only); view buttons By Category | Details above the tables (client side:
    custom.js [data-report-view]; both tables follow the same filters). A By Category row = Details of that category (+ Back link);
    a Details row = the Cash Out popup.
--}}
@section('title')
    Expenses
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.expenses.excel') }}" data-export="#expense-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'Cash Out by date: shift and company.',
        'details' => 'Shift Cash Out = recorded in a login\'s shift (it is in the shift hand-over). Company Cash Out = advances and salaries paid by the company, in no shift. Voided / deleted rows are left out. Refunds are not here: they are in the Income report.'])

    <form id="expense-filters" class="report-toolbar mt-5">
        <div class="filter-bar">
            <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Item, notes or No." autocomplete="off">
            @include('reportmodule::Report.partials.period-picker', ['initial' => $initial])
        </div>
        <div class="filter-bar">
            <select name="category_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Category">
                <option value="">All Categories</option>
                @include('layoutmodule::partials.filter-options', ['options' => $categories])
            </select>
            <select name="method" class="{{ config('layoutmodule.form.select') }}" aria-label="Method">
                <option value="">All Methods</option>
                @include('layoutmodule::partials.filter-options', ['options' => $methods])
            </select>
            <select name="kind" class="{{ config('layoutmodule.form.select') }}" aria-label="Shift or company">
                <option value="">Shift &amp; Company</option>
                @foreach (\Modules\ReportModule\app\Services\ExpenseReport::KINDS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @include('layoutmodule::partials.datatable-clear')
        </div>

        <div data-html="expense-totals">@include('reportmodule::Report.partials.expense-totals', ['totals' => null])</div>
    </form>

    <div class="report-view-bar mt-5">
        <div class="report-segments" role="group" aria-label="View">
            <button type="button" class="active" data-report-view="categories">By Category</button>
            <button type="button" data-report-view="details">Details</button>
        </div>
        {{-- shown after a click on a By Category row (custom.js "Report drill-down") --}}
        <div class="report-back" data-report-back="#expense-filters" hidden>
            <a href="#">← Back to categories</a> <span class="text-slate-500">· Details of <b data-report-back-label></b></span>
        </div>
    </div>

    <div class="table-wrap mt-3" data-report-panel="categories">
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.expenses.categories') }}"
            data-filters="#expense-filters" data-order='[]' data-page-length="100" data-empty="No Cash Out in these dates.">
            <thead>
                <tr>
                    <th data-data="block" data-name="block">Block</th>
                    <th data-data="category" data-name="category">Category</th>
                    <th data-data="count" data-name="count" data-class="text-center">Count</th>
                    <th data-data="amount" data-name="amount" data-class="text-right whitespace-nowrap">Amount</th>
                    <th data-data="cmp_amount" data-name="cmp_amount" data-class="text-right whitespace-nowrap" data-visible-if="compare"><span data-html="expense-cmp-title">Compare</span></th>
                    <th data-data="change" data-orderable="false" data-class="text-right whitespace-nowrap" data-visible-if="compare">Change</th>
                </tr>
            </thead>
            <tfoot>
                <tr class="report-total">
                    <td colspan="2" data-html="expense-foot-label">Total</td>
                    <td class="text-center" data-html="expense-foot-count"></td>
                    <td class="text-right whitespace-nowrap money-out" data-html="expense-foot-amount"></td>
                    <td class="text-right whitespace-nowrap" data-html="expense-foot-cmp_amount"></td>
                    <td class="text-right whitespace-nowrap" data-html="expense-foot-change"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="table-wrap mt-3" data-report-panel="details" hidden>
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.expenses.details') }}"
            data-filters="#expense-filters" data-order='[]' data-page-length="25" data-empty="No Cash Out in these dates.">
            <thead>
                <tr>
                    <th data-data="expense_no" data-name="expense_no">No.</th>
                    <th data-data="spent_at" data-name="spent_at" data-class="whitespace-nowrap">Date</th>
                    <th data-data="category" data-orderable="false">Category</th>
                    <th data-data="item" data-name="item">Item</th>
                    <th data-data="method" data-orderable="false">Method</th>
                    <th data-data="by" data-orderable="false">By</th>
                    <th data-data="amount" data-name="amount" data-class="text-right whitespace-nowrap">Amount</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
