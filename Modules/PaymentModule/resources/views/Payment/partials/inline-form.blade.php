{{--
    Add Cash In inside a reservation view (2026-10-21): the "Cash In" fields of Payment/partials/form, hidden until "Pay" /
    "+ Cash In" ([data-reveal], custom.js); the item is fixed (hidden inputs), the amount = its remaining (also its max: PaymentService refuses more).
    Saved by ajax to {area}.payments.store with back_to_payable: the popup shows the view again with the new payment
    (full page: redirect to it). Cancel hides it.
    @include('paymentmodule::Payment.partials.inline-form', ['payable' => $reservation, 'kind' => 'reservation', 'methods' => $methods])
--}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<form method="POST" action="{{ route($area . '.payments.store') }}" id="cash-in-form" class="cash-in-inline" hidden
    data-ajax data-payment-form
    data-confirm="The cash in will be added. Only the account owner can change or delete it later."
    data-confirm-title="Add this cash in?"
    data-confirm-button="Add"
    data-confirm-variant="success">
    @csrf
    <input type="hidden" name="payable" value="{{ $kind }}">
    <input type="hidden" name="payable_id" value="{{ $payable->getKey() }}">
    <input type="hidden" name="member_id" value="{{ $payable->payableMemberId() ?: '' }}">
    <input type="hidden" name="back_to_payable" value="1">

    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
        {{-- Settings › General "Withholding tax" off: payments only --}}
        @if (\Modules\PaymentModule\app\Services\PaymentService::withholdingOn())
            @include('layoutmodule::partials.field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true,
                'options' => ['payment' => 'Cash In', 'withholding' => 'Withholding'], 'value' => 'payment'])
        @else
            <input type="hidden" name="type" value="payment">
        @endif
        @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true,
            'value' => max(0, (float) $payable->remaining) ?: null, 'attrs' => 'min="0.01" step="0.01" max="' . $payable->remaining . '"',
            'hint' => 'Not more than the remaining ' . number_format($payable->remaining, 2) . '.'])
        @include('paymentmodule::Payment.partials.date-field', ['name' => 'paid_at'])
    </div>
    <div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
        <div data-show-if="type=payment">
            @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Payment Method', 'type' => 'select', 'required' => true, 'searchable' => true,
                'options' => ['' => '- Select -'] + $methods,
                'hint' => empty($methods) ? 'No payment methods yet, add them from Settings > Payment Methods.' : null])
        </div>
        @include('paymentmodule::Payment.partials.reference-field')
        @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes'])
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save</button>
        <button type="button" data-reveal-close class="btn btn-secondary">Cancel</button>
    </div>
</form>
