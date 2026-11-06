@extends(Auth::user()->userable->layout())

{{--
    Report 1 — Outstanding & credits (docs/reports-plan.md §1): one row per member, today's balances; the member opens his rows
    (popup). Toolbar: search | date (the row's start) | type → space → plan | show | include future | Clear.
--}}
@section('title')
    Outstanding &amp; Credits
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.outstanding.excel') }}" data-export="#outstanding-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'Who owes and who has credit today.',
        'details' => 'Reservations paid on their own + packages, by today\'s stored balance. The date filter = the row\'s start. Future periods are left out of Owed unless "Include future" is ticked; a credit always shows. A type / space / plan filter leaves the packages out.'])

    <form id="outstanding-filters" class="report-toolbar mt-5">
        <div class="filter-bar">
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Member name or phone" autocomplete="off">
        @include('layoutmodule::partials.date-range-filter', ['presets' => \Modules\ReportModule\app\Services\ReportDates::presets()])
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
        <select name="plan_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Plan" data-filter-parents="space_id">
            <option value="">All Plans</option>
            @foreach ($plans as $id => $plan)
                <option value="{{ $id }}" @class(['option-inactive' => !$plan['active']]) data-match="{{ collect($plan['spaces'])->map(fn ($space) => 'space_id:' . $space)->implode(' ') }}">{{ $plan['name'] }}{{ $plan['active'] ? '' : ' (Inactive)' }}</option>
            @endforeach
        </select>
        <select name="show" class="{{ config('layoutmodule.form.select') }}" aria-label="Show">
            <option value="">Owed &amp; Credit</option>
            @foreach (\Modules\ReportModule\app\Services\OutstandingReport::SHOW as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <label class="filter-check">
            <input type="checkbox" name="include_future" value="1" class="{{ config('layoutmodule.form.checkbox') }}"> Include future
        </label>
        @include('layoutmodule::partials.datatable-clear')
        </div>
    </form>

    <div class="mt-5" data-html="outstanding-totals">@include('reportmodule::Report.partials.outstanding-totals', ['totals' => null])</div>

    <div class="table-wrap mt-5">
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.outstanding.data') }}"
            data-filters="#outstanding-filters" data-order='[[1, "desc"]]' data-page-length="25" data-empty="Nobody owes and nobody has credit.">
            <thead>
                <tr>
                    <th data-data="member_html" data-name="member_name">Member</th>
                    <th data-data="owed" data-name="owed" data-class="text-right whitespace-nowrap">Owed</th>
                    <th data-data="credit" data-name="credit" data-class="text-right whitespace-nowrap">Credit</th>
                    <th data-data="net" data-name="net" data-class="text-right whitespace-nowrap">Net</th>
                    <th data-data="periods" data-name="periods" data-class="text-center">Periods</th>
                    <th data-data="oldest" data-name="oldest" data-class="whitespace-nowrap">Oldest Due Since</th>
                </tr>
            </thead>
            <tfoot>
                <tr class="report-total">
                    <td data-html="outstanding-foot-members">Total</td>
                    <td class="text-right whitespace-nowrap money-out" data-html="outstanding-foot-owed"></td>
                    <td class="text-right whitespace-nowrap money-withholding" data-html="outstanding-foot-credit"></td>
                    <td class="text-right whitespace-nowrap" data-html="outstanding-foot-net"></td>
                    <td class="text-center" data-html="outstanding-foot-periods"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
