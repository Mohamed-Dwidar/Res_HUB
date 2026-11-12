@extends(Auth::user()->userable->layout())

{{--
    Report 3 — Income (docs/reports-plan.md §3), cash basis: cash in + withholding − refunds by payment date, grouped by one of
    Space › Unit | Type | Plan | Method | Month (segmented buttons above the table). Toolbar rows: the date field (starts on this month,
    presets Today / This month / Last month / All inside it; 2026-11-12: the period picker — Month / Quarter / Half-year / Year /
    Custom + Compare with: the comparison's Net + Change columns, changes in the totals box) | type → space → unit (once a space is chosen) / plan, method, Clear.
--}}
@section('title')
    Income
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.income.excel') }}" data-export="#income-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'Money received by payment date.',
        'details' => 'Cash in + withholding − refunds (a refund counts on its own date). Other income only from categories counted as income. Package payments are one "Packages" row; a type / space / unit / plan filter shows reservation payments only. Tax = the VAT + table tax part of invoiced rows; Net = before tax.'])

    <form id="income-filters" class="report-toolbar mt-5">
        <div class="filter-bar">
            @include('reportmodule::Report.partials.period-picker', ['initial' => $initial])
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
            <select name="plan_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Plan" data-filter-parents="space_id">
                <option value="">All Plans</option>
                @foreach ($plans as $id => $plan)
                    <option value="{{ $id }}" @class(['option-inactive' => !$plan['active']]) data-match="{{ collect($plan['spaces'])->map(fn ($space) => 'space_id:' . $space)->implode(' ') }}">{{ $plan['name'] }}{{ $plan['active'] ? '' : ' (Inactive)' }}</option>
                @endforeach
            </select>
            <select name="method" class="{{ config('layoutmodule.form.select') }}" aria-label="Method">
                <option value="">All Methods</option>
                @include('layoutmodule::partials.filter-options', ['options' => $methods])
                <option value="withholding">Withholding (certificates)</option>
            </select>
            @include('layoutmodule::partials.datatable-clear')
        </div>

        <div data-html="income-totals">@include('reportmodule::Report.partials.income-totals', ['totals' => null])</div>

        {{-- Group by: a view choice above the table (data-filter-keep: sent, not counted / reset by Clear) --}}
        <div>
            <input type="hidden" name="group" value="" data-filter-keep>
            <div class="report-segments" role="group" aria-label="Group by">
                @foreach (\Modules\ReportModule\app\Services\IncomeReport::GROUPS as $value => $label)
                    <button type="button" data-filter-tab data-name="group" data-value="{{ $value === 'unit' ? '' : $value }}" @class(['active' => $value === 'unit'])>{{ ['unit' => 'Space › Unit', 'type' => 'Type', 'plan' => 'Plan', 'method' => 'Method', 'month' => 'Month'][$value] }}</button>
                @endforeach
            </div>
        </div>
    </form>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.income.data') }}"
            data-filters="#income-filters" data-order='[]' data-page-length="100" data-empty="No income in these dates.">
            <thead>
                <tr>
                    <th data-data="group_label" data-name="group_label"><span data-html="income-group-title">Space › Unit</span></th>
                    <th data-data="payments" data-name="payments" data-class="text-center">Payments</th>
                    <th data-data="cash_in" data-name="cash_in" data-class="text-right whitespace-nowrap">Cash In</th>
                    <th data-data="refunds" data-name="refunds" data-class="text-right whitespace-nowrap">Refunds</th>
                    <th data-data="withholding" data-name="withholding" data-class="text-right whitespace-nowrap">Withholding</th>
                    <th data-data="tax" data-name="tax" data-class="text-right whitespace-nowrap">Tax</th>
                    <th data-data="net" data-name="net" data-class="text-right whitespace-nowrap">Net (before tax)</th>
                    <th data-data="total" data-name="total" data-class="text-right whitespace-nowrap">Total</th>
                    <th data-data="cmp_net" data-name="cmp_net" data-class="text-right whitespace-nowrap" data-visible-if="compare"><span data-html="income-cmp-title">Compare</span></th>
                    <th data-data="change" data-orderable="false" data-class="text-right whitespace-nowrap" data-visible-if="compare">Change</th>
                </tr>
            </thead>
            <tfoot>
                <tr class="report-total">
                    <td data-html="income-foot-label">Total</td>
                    <td class="text-center" data-html="income-foot-payments"></td>
                    <td class="text-right whitespace-nowrap money-in" data-html="income-foot-cash_in"></td>
                    <td class="text-right whitespace-nowrap money-out" data-html="income-foot-refunds"></td>
                    <td class="text-right whitespace-nowrap money-withholding" data-html="income-foot-withholding"></td>
                    <td class="text-right whitespace-nowrap" data-html="income-foot-tax"></td>
                    <td class="text-right whitespace-nowrap" data-html="income-foot-net"></td>
                    <td class="text-right whitespace-nowrap" data-html="income-foot-total"></td>
                    <td class="text-right whitespace-nowrap" data-html="income-foot-cmp_net"></td>
                    <td class="text-right whitespace-nowrap" data-html="income-foot-change"></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
