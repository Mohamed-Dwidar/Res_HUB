@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    // Settings › General "Withholding tax" off: no certificates button, no Withholding filter option
    $withholdingOn = \Modules\PaymentModule\app\Services\PaymentService::withholdingOn();
@endphp
@extends(Auth::user()->userable->layout())

@section('title')
    Payments
@endsection

@section('actions')
    @if ($area === 'employee')
        <a href="{{ route('employee.shift.index') }}" class="btn btn-secondary"><i data-lucide="lock"></i> My Shift</a>
    @else
        <a href="{{ route('account.shift.index') }}" class="btn btn-secondary"><i data-lucide="lock"></i> My Shift</a>
        <a href="{{ route('account.daily-summary.index') }}" class="btn btn-secondary"><i data-lucide="calendar-days"></i> Daily Summary</a>
    @endif
    @if ($area === 'account' && $withholdingOn)
        <a href="{{ route('account.withholding-certificates.index') }}" class="btn btn-secondary"><i data-lucide="file-badge"></i> Withholding Certificates</a>
    @endif
    <a href="{{ route($area . '.payments.create') }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Cash In</a>
    <a href="{{ route($area . '.expenses.create') }}" data-modal class="btn btn-danger"><i data-lucide="plus"></i> Cash Out</a>
@endsection

@section('content')
    {{--
        Payments = Cash In + Cash Out (docs/payments-plan.md "Cash In / Cash Out", 2026-10-14); the data stays in payments / expenses.
        Page tabs (links, ?type=): All (default) | Cash In | Cash Out; the tab's direction is in its table's data URL (not a form
        field: Clear resets the hidden fields and counts them). Each tab: date tabs Today (default) | This Month | All (account;
        employees: no date tabs, ONE day, today by default, their own rows only) + ONE date field synced with them, then:
        - All: search | date | Method | Clear; the list = payments + expenses (cash-table), summary In · Out · Net per method.
        - Cash In: the payments list (no refunds): search | date | For | Method (+ Withholding) | Clear (list-table, list-summary).
        - Cash Out: expenses + refunds: search | date | Category (+ Refunds) | Method | Clear (cash-table).
    --}}
    <nav class="page-tabs">
        @foreach (\Modules\PaymentModule\app\Services\CashListService::TYPES as $value => $label)
            <a href="{{ route($area . '.payments.index', $value === 'all' ? [] : ['type' => $value]) }}" @class(['active' => $type === $value])>{{ $label }}</a>
        @endforeach
    </nav>

    <form id="payments-filters" class="filter-bar">
        {{-- employees: no tabs, the date field only (one day, today by default, × / Clear = today) --}}
        @if ($area === 'account')
            {{-- opened from the Members list (?member_id=): the All tab --}}
            @include('layoutmodule::partials.filter-tabs', ['tabs' => \Modules\PaymentModule\app\Models\Payment::TABS, 'default' => 'today', 'initial' => $member ? 'all' : null, 'dates' => \Modules\PaymentModule\app\Models\Payment::tabDates()])
        @endif
        <input type="search" name="search" class="{{ config('layoutmodule.form.input') }}"
            placeholder="{{ $type === 'in' ? 'No., reference, member name or phone' : 'No., reference, item, member name or phone' }}" autocomplete="off">
        @include('layoutmodule::partials.date-range-filter', $area === 'employee' ? ['single' => true, 'default' => today()->toDateString()] : [])
        {{-- ?member_id= (the Members list): this member only, chosen when the list opens; Clear / "All Members" = everyone --}}
        @if ($member)
            <select name="member_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Member" data-initial="{{ $member->id }}">
                <option value="">All Members</option>
                <option value="{{ $member->id }}">{{ $member->name }}</option>
            </select>
        @endif
        @if ($type === 'in')
            <select name="for" class="{{ config('layoutmodule.form.select') }}" aria-label="For">
                <option value="">All (For)</option>
                <option value="reservation">Reservations</option>
                <option value="package">Packages</option>
                <optgroup label="Other income">
                    <option value="income">All other income</option>
                    @include('layoutmodule::partials.filter-options', ['options' => $categories, 'prefix' => 'category-'])
                </optgroup>
            </select>
        @elseif ($type === 'out')
            <select name="category" class="{{ config('layoutmodule.form.select') }}" aria-label="Category">
                <option value="">All Categories</option>
                @include('layoutmodule::partials.filter-options', ['options' => $expenseCategories])
                <option value="refund">Refunds</option>
            </select>
        @endif
        <select name="method" class="{{ config('layoutmodule.form.select') }}" aria-label="Method">
            <option value="">All Methods</option>
            @include('layoutmodule::partials.filter-options', ['options' => $methods])
            @if ($type === 'in' && $withholdingOn)
                <option value="withholding">Withholding</option>
            @endif
        </select>
        @include('layoutmodule::partials.datatable-clear')
    </form>

    {{-- employees see only their own rows: no Created By / By --}}
    @if ($type === 'in')
        <div class="list-summary" data-html="payments-summary"></div>
        @include('paymentmodule::Payment.partials.list-table', ['filters' => '#payments-filters', 'showBy' => $area === 'account', 'dataParams' => ['direction' => 'in']])
    @else
        <div class="list-summary" data-html="cash-summary"></div>
        @include('paymentmodule::Payment.partials.cash-table', ['filters' => '#payments-filters', 'showBy' => $area === 'account', 'direction' => $type])
    @endif
@endsection

@include('layoutmodule::partials.datatable-assets')
