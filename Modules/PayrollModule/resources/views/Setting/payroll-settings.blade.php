{{--
    HR › Payroll Settings (the account only, docs/hr-plan.md §3.3): the social insurance shares (empty = not set yet: a warning on
    the insured staff) and the days of a month for the daily rate. Income tax + the payroll rules come with H5.
--}}
@extends('layoutmodule::account.main')

@section('title')
    Payroll Settings
@endsection

@section('content')
    <form method="POST" action="{{ route('account.hr.payroll-settings.update') }}" data-ajax>
        @csrf
        @method('PUT')

        <div class="form-section">Social Insurance</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'insurance_employee_percent', 'label' => 'Employee Share %', 'type' => 'number',
                'value' => $settings->insurance_employee_percent, 'attrs' => 'min=0 max=100 step=0.01',
                'hint' => 'Of the insured wage, deducted from the salary. Empty until the accounting office gives it.'])
            @include('layoutmodule::partials.field', ['name' => 'insurance_company_percent', 'label' => 'Company Share %', 'type' => 'number',
                'value' => $settings->insurance_company_percent, 'attrs' => 'min=0 max=100 step=0.01',
                'hint' => 'Of the insured wage, paid by the company.'])
        </div>

        <div class="form-section mt-8">Daily Rate</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'month_days', 'label' => 'Days in a Month', 'type' => 'number', 'required' => true,
                'value' => $settings->month_days, 'attrs' => 'min=1 max=31 step=1',
                'hint' => 'Daily rate = monthly salary ÷ this number (deductions in days, unpaid leave).'])
        </div>

        <div class="form-section mt-8">Advances</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'advance_warn_percent', 'label' => 'Warn Above % of Salary', 'type' => 'number', 'required' => true,
                'value' => $settings->advance_warn_percent ?? 50, 'attrs' => 'min=1 max=100 step=0.01',
                'hint' => 'A yellow warning (never a block) when a month\'s advance installments go above this % of the person\'s salary.'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.hr.staff.index')])
    </form>
@endsection
