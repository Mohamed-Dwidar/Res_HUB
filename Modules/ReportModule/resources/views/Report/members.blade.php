@extends(Auth::user()->userable->layout())

{{--
    Report 8 — Members (MemberReport): New | Top members | Inactive | By occupation. Page pattern: intro + (i); rows: search + date
    (starts on this month, presets) + "Inactive after N days" | space type, Clear; totals box; tab buttons with counts (hidden "view",
    data-filter-keep) that also switch the panel (custom.js [data-report-view]: the 3 member tabs share one table, By occupation its
    own). A member row opens the member statement in a new tab (tr.report-click data-open-page).
--}}
@section('title')
    Members
@endsection

@section('actions')
    <a href="{{ route($area . '.reports.members.excel') }}" data-export="#member-filters" class="btn btn-secondary"><i data-lucide="file-spreadsheet"></i> Export Excel</a>
@endsection

@section('content')
    @include('reportmodule::Report.partials.intro', ['text' => 'New members, top members by paid, inactive members, members by occupation.',
        'details' => 'First visit = the first reservation or package (any date); Last visit = the latest reservation that has started; Reservations = every reservation, cancelled included. New = first visit in the dates. Top members = paid in the dates (cash in − refunds, with tax). Commitment % = not cancelled ÷ all reservations, all time. Inactive = regulars who stopped: at least the minimum reservations, the last one between "Last visit within" and "No visit for" days ago (a future booking or a running period keeps a member active; the dates do not apply). By occupation = members with a reservation or a payment in the dates. Balance = charges − payments dated up to the end of the dates, never after today (no future charges; + owes, − credit). A space type / space / unit keeps only its reservations and their payments. The phone opens WhatsApp.'])

    <form id="member-filters" class="report-toolbar mt-5">
        <div class="filter-bar">
            <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}" placeholder="Name, phone or company" autocomplete="off">
            @include('layoutmodule::partials.date-range-filter', ['initial' => $initial, 'presets' => \Modules\ReportModule\app\Services\ReportDates::presets()])
            {{-- Inactive tab only (custom.js [data-tab-only]) --}}
            <span class="filter-bar__group" data-tab-only="inactive" hidden>
                <label class="filter-check">Min. reservations
                    <input type="number" name="min_reservations" value="{{ $min }}" min="1" max="1000" class="{{ config('layoutmodule.form.input') }} report-days" data-filter-keep aria-label="Min. reservations">
                </label>
                <label class="filter-check">No visit for
                    <input type="number" name="days" value="{{ $days }}" min="1" max="3650" class="{{ config('layoutmodule.form.input') }} report-days" data-filter-keep aria-label="Days"> days
                </label>
                <label class="filter-check">Last visit within
                    <input type="number" name="within" value="{{ $within }}" min="2" max="7300" class="{{ config('layoutmodule.form.input') }} report-days" data-filter-keep aria-label="Last visit within"> days
                </label>
            </span>
        </div>
        <div class="filter-bar">
            <select name="subscription_type_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Space type">
                <option value="">All Space Types</option>
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
            <select name="commitment" class="{{ config('layoutmodule.form.select') }}" aria-label="Commitment">
                <option value="">All Commitment</option>
                @foreach (\Modules\ReportModule\app\Services\MemberReport::COMMITMENT_BELOW as $percent)
                    <option value="{{ $percent }}">Commitment &lt; {{ $percent }}%</option>
                @endforeach
            </select>
            @include('layoutmodule::partials.datatable-clear')
        </div>

        <div data-html="member-totals">@include('reportmodule::Report.partials.member-totals', ['totals' => null, 'filters' => ['view' => 'new'], 'range' => ''])</div>

        <div>
            <input type="hidden" name="view" value="" data-filter-keep>
            <div class="report-segments" role="group" aria-label="Tab">
                @foreach (\Modules\ReportModule\app\Services\MemberReport::TABS as $value => $label)
                    <button type="button" data-filter-tab data-name="view" data-value="{{ $value === 'new' ? '' : $value }}"
                        data-report-view="{{ $value === 'occupation' ? 'occupation' : 'members' }}" data-report-tab="{{ $value }}" @class(['active' => $value === 'new']) @if ($value !== 'new' && request('view') === $value) data-initial-tab @endif>
                        {{ $label }} (<span data-counter="members-{{ $value }}">0</span>)
                    </button>
                @endforeach
            </div>
        </div>
    </form>

    <div class="table-wrap mt-3" data-report-panel="members">
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.members.data') }}"
            data-filters="#member-filters" data-order='[]' data-page-length="25" data-empty="No members here.">
            <thead>
                <tr>
                    <th data-data="name" data-name="name">Member</th>
                    <th data-data="phone" data-name="phone" data-class="whitespace-nowrap">Phone</th>
                    <th data-data="reservations" data-name="reservations" data-class="text-center">Reservations</th>
                    <th data-data="first_visit" data-name="first_visit" data-class="whitespace-nowrap">First Visit</th>
                    <th data-data="last_visit" data-name="last_visit" data-class="whitespace-nowrap">Last Visit</th>
                    <th data-data="paid" data-name="paid" data-class="text-right whitespace-nowrap">Paid</th>
                    <th data-data="balance" data-name="balance" data-class="text-right whitespace-nowrap">Balance</th>
                    <th data-data="commitment" data-name="commitment" data-class="text-center">Commitment</th>
                </tr>
            </thead>
        </table>
    </div>

    <div class="table-wrap mt-3" data-report-panel="occupation" hidden>
        <table class="data-table" data-datatable data-url="{{ route($area . '.reports.members.occupations') }}"
            data-filters="#member-filters" data-order='[]' data-page-length="100" data-empty="No members in these dates.">
            <thead>
                <tr>
                    <th data-data="occupation" data-name="occupation">Occupation</th>
                    <th data-data="members" data-name="members" data-class="text-center">Members</th>
                    <th data-data="new_members" data-name="new_members" data-class="text-center">New Members</th>
                    <th data-data="paid" data-name="paid" data-class="text-right whitespace-nowrap">Paid</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@include('layoutmodule::partials.datatable-assets')
