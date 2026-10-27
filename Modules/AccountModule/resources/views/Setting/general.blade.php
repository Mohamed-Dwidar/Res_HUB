@extends('layoutmodule::account.main')

{{--
    Settings › General (the account only, docs/payments-plan.md): two switches stored on the account + the Schedule page settings (timeline hours, renewals window).
    Withholding tax OFF: no new withholding anywhere (payment type, Method filter, certificates page + menu); existing withholding
    records stay and are still shown. VAT: for Phase 2 (OFF = prices tax free); nothing else changes now.
--}}
@section('title')
    General Settings
@endsection

@section('content')
    <form method="POST" action="{{ route('account.settings.general.update') }}" data-ajax
        data-confirm="The settings apply to you and all your employees. No payment or certificate is changed."
        data-confirm-title="Save the settings?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        <div class="form-section">Taxes</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.checkbox', ['name' => 'withholding_enabled', 'label' => 'Withholding tax', 'checked' => $account->withholdingEnabled(),
                'hint' => 'Off: "Withholding" is no longer offered when adding payments or in the filters, and the Withholding Certificates page is hidden. Existing withholding records stay and are still shown. Turning it on again restores everything.'])
            @include('layoutmodule::partials.checkbox', ['name' => 'vat_enabled', 'label' => 'VAT', 'checked' => $account->vatEnabled(),
                'hint' => 'For invoices (coming next): off = all prices are tax free, no tax rates or tax lines. Nothing changes today.'])
        </div>

        <div class="form-section mt-8">Schedule Page</div>
        @php($hourOptions = collect(range(0, 30))->mapWithKeys(fn ($hour) => [$hour => sprintf('%02d:00', $hour % 24) . ($hour >= 24 ? ' · Next Day' : '')])->all())
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'today_hours_from', 'label' => 'Timeline From', 'type' => 'select', 'required' => true,
                'options' => array_slice($hourOptions, 0, 24, true), 'value' => $account->todayHours()[0],
                'hint' => 'The first hour of the hourly timelines.'])
            @include('layoutmodule::partials.field', ['name' => 'today_hours_to', 'label' => 'Timeline To', 'type' => 'select', 'required' => true,
                'options' => array_slice($hourOptions, 1, null, true), 'value' => $account->todayHours()[1],
                'hint' => 'After midnight (up to 06:00 the next day) = open through the night: until then the night still counts as the day before.'])
            @include('layoutmodule::partials.field', ['name' => 'renewal_days', 'label' => 'Renewals Window (days)', 'type' => 'number', 'required' => true,
                'value' => $account->renewalDays(), 'attrs' => 'min=1 max=90 step=1',
                'hint' => 'Subscriptions ending within these days are listed under "Next days".'])
        </div>

        {{-- fraud prevention (2026-10-27): the "Late entry" badge + the Payment Exceptions report --}}
        <div class="form-section mt-8">Payment Controls</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'late_entry_hours', 'label' => 'Late Entry After (hours)', 'type' => 'number', 'required' => true,
                'value' => $account->lateEntryHours(), 'attrs' => 'min=0 max=720 step=1',
                'hint' => 'A cash in / refund / cash out recorded this long after the end of its business date is marked "Late entry".'])
            @include('layoutmodule::partials.field', ['name' => 'expense_review_amount', 'label' => 'Review Cash Out From', 'type' => 'number', 'required' => true,
                'value' => $account->expenseReviewAmount(), 'attrs' => 'min=0 step=0.01',
                'hint' => 'Employees\' cash outs from this amount are listed in Payment Exceptions › Expenses.'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.dashboard')])
    </form>
@endsection
