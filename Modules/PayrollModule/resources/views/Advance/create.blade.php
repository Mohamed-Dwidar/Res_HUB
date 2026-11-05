{{-- Give an advance (popup): the money = a company Cash Out (outside every shift); repaid by monthly installments through payroll --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Give Advance · {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.payroll.advances.store', $staff->id) }}" data-ajax data-advance-form
        data-salary="{{ $salary?->total ?? 0 }}" data-warn-percent="{{ $warnPercent }}" data-month-totals="{{ json_encode($monthTotals) }}"
        data-confirm="The money is recorded as a company Cash Out (not in any shift)." data-confirm-title="Give this advance?" data-confirm-button="Give" data-confirm-variant="success">
        @csrf
        @if ($salary)
            <div class="notice notice--info mb-4">Salary now: {{ number_format($salary->total, 2) }} (basic {{ number_format($salary->basic, 2) }}).</div>
        @endif
        {{-- custom.js advanceWarn(): months where the installments (this + the open advances) go above the settings' % of the salary --}}
        <div class="notice notice--warning mb-4" data-advance-warning hidden></div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'attrs' => 'min=0.01 step=0.01 autofocus'])
            @include('layoutmodule::partials.field', ['name' => 'given_on', 'label' => 'Date Given', 'type' => 'date', 'required' => true, 'value' => today()->toDateString()])
            @include('layoutmodule::partials.field', ['name' => 'installments', 'label' => 'Installments (months)', 'type' => 'number', 'required' => true, 'value' => 1,
                'attrs' => 'min=1 max=60 step=1', 'hint' => 'Monthly installment = amount ÷ months (rounded up).'])
            @include('layoutmodule::partials.field', ['name' => 'first_period', 'label' => 'First Deduction Month', 'type' => 'select', 'required' => true,
                'options' => $months, 'value' => today()->startOfMonth()->addMonth()->toDateString()])
            @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Paid From', 'type' => 'select', 'required' => true,
                'options' => ['' => '- Choose -'] + $methods, 'hint' => 'Company cash, bank transfer, wallet …'])
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'attrs' => 'maxlength="255"'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'advances'])])
    </form>
@endsection
