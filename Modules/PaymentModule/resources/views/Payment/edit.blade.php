@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Edit {{ $payment->type_label }} #{{ $payment->payment_no }}
@endsection

@section('modal-size', 'lg')

{{-- the owner only (account routes); the payable, member and type are kept: a payment for something else = delete + add;
     in a closed shift: a reason is required (logged on the shift, its totals recalculated) --}}
@section('content')
    <form method="POST" action="{{ route('account.payments.update', $payment->id) }}" data-ajax data-row="{{ $payment->id }}"
        data-confirm="{{ $payment->type_label }} #{{ $payment->payment_no }} will be changed, the paid / remaining of what it pays are calculated again."
        data-confirm-title="Save the changes?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('paymentmodule::Payment.partials.payment-summary')
        @include('paymentmodule::Payment.partials.closed-shift-note')

        <div class="form-section mt-6">{{ $payment->type_label }}</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'value' => $payment->amount,
                'attrs' => 'min="0.01" step="0.01" max="99999999"'])
            @include('layoutmodule::partials.field', ['name' => 'paid_at', 'label' => 'Date', 'type' => 'date', 'required' => true,
                'value' => $payment->paid_at?->format('Y-m-d')])
            @unless ($payment->type === 'withholding')
                @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Payment Method', 'type' => 'select', 'required' => true, 'searchable' => true,
                    'options' => ['' => '- Select -'] + $methods, 'value' => $payment->payment_method_id ?: null])
            @endunless
        </div>
        <div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
            @unless ($payment->payable_type)
                @include('layoutmodule::partials.field', ['name' => 'income_category_id', 'label' => 'Income Category', 'type' => 'select', 'required' => true, 'searchable' => true,
                    'options' => ['' => '- Select -'] + $categories, 'value' => $payment->income_category_id ?: null])
            @endunless
            @include('paymentmodule::Payment.partials.reference-field', ['value' => $payment->reference, 'hint' => 'Checked when the method or the reference changes.'])
        </div>
        <div class="mt-5">
            @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $payment->notes])
        </div>
        @if ($closedShift)
            <div class="mt-5">
                @include('layoutmodule::partials.field', ['name' => 'change_reason', 'label' => 'Reason for the Change', 'type' => 'textarea', 'required' => true,
                    'attrs' => 'maxlength="255"', 'hint' => 'Kept in the shift\'s history with the values before and after.'])
            </div>
        @endif

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.payments.show', $payment->id)])
    </form>
@endsection
