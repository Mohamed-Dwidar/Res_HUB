@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Refund Cash In #{{ $payment->payment_no }}
@endsection

@section('modal-size', 'lg')

{{-- the account or an employee: a new refund record (type refund, refund_of = this payment) for the same reservation / package /
     income, in the refunder's own open shift; reason required; the server checks the limit again (this payment less its refunds) --}}
@section('content')
    <form method="POST" action="{{ route($area . '.payments.refund.store', $payment->id) }}" data-ajax
        data-confirm="A refund will be added to your shift: the money goes back to the member from your drawer."
        data-confirm-title="Add this refund?"
        data-confirm-button="Refund"
        data-confirm-variant="danger">
        @csrf

        @include('paymentmodule::Payment.partials.payment-summary')
        @include('paymentmodule::Payment.partials.closed-shift-note', ['refund' => true])

        <div class="form-section mt-6">Refund</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true,
                'value' => max(0, min($refundable, $payment->amount)) ?: null,
                'attrs' => 'min="0.01" step="0.01" max="' . max(0, $refundable) . '"',
                'hint' => 'Up to ' . number_format(max(0, $refundable), 2) . ' (this cash in, less its earlier refunds).'])
            @include('paymentmodule::Payment.partials.date-field', ['name' => 'paid_at'])
            @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Paid Back By', 'type' => 'select', 'required' => true, 'searchable' => true,
                'options' => ['' => '- Select -'] + $methods, 'value' => $payment->payment_method_id ?: null])
        </div>
        <div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
            @include('paymentmodule::Payment.partials.reference-field', ['hint' => 'Cash: empty = "Refund of #' . $payment->payment_no . '".'])
        </div>
        <div class="mt-5">
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true,
                'attrs' => 'maxlength="255"', 'hint' => 'Why the money is given back (shown in the Daily Summary).'])
        </div>
        <div class="mt-5">
            @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.payments.show', $payment->id)])
    </form>
@endsection
