{{--
    An advance move (popup): $kind = repay (cash back: a Cash In "Advance repayment" in YOUR open shift) | change (a new monthly
    installment from a month, 0 = pause). $remaining, $installment, $methods, $months.
--}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ ['repay' => 'Cash Repayment', 'change' => 'Change Installment'][$kind] }} · Advance #{{ $advance->advance_no }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.payroll.advances.moves.store', [$staff->id, $advance->id, $kind]) }}" data-ajax>
        @csrf
        <div class="notice notice--info mb-4">{{ $staff->name }} · remaining {{ number_format($remaining, 2) }} · installment {{ number_format($installment, 2) }}</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @if ($kind === 'repay')
                @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount Received', 'type' => 'number', 'required' => true, 'value' => $remaining,
                    'attrs' => 'min=0.01 step=0.01 max=' . $remaining . ' autofocus'])
                @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Method', 'type' => 'select', 'required' => true, 'options' => ['' => '- Choose -'] + $methods])
                @if ($owner)
                    @include('layoutmodule::partials.field', ['name' => 'paid_at', 'label' => 'Date', 'type' => 'date', 'value' => today()->toDateString()])
                @endif
                @include('layoutmodule::partials.field', ['name' => 'reference', 'label' => 'Reference No.', 'attrs' => 'maxlength="255"'])
                <div class="md:col-span-2 text-sm text-slate-500">Recorded as a Cash In "Advance repayment" in your open shift (you hand the money over).</div>
            @else
                @include('layoutmodule::partials.field', ['name' => 'period', 'label' => 'From Month', 'type' => 'select', 'required' => true, 'options' => $months])
                @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'New Monthly Installment', 'type' => 'number', 'required' => true, 'value' => $installment,
                    'attrs' => 'min=0 step=0.01', 'hint' => '0 = pause the deductions from that month.'])
            @endif
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'required' => $kind !== 'repay', 'attrs' => 'maxlength="255"', 'wrapperClass' => 'md:col-span-2'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'advances'])])
    </form>
@endsection
