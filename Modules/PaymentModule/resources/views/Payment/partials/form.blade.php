{{--
    Add payment fields (no edit here: the owner's edit is step 6). Every drop menu is searchable (Tom Select).
    For: a reservation / a package of the member (loaded by ajax from the member + "for", custom.js [data-options-url]
    and [data-payment-form]), or other income (an income category, the member is optional).
    Opened for one reservation / package ($payable: its view "Pay" / "+ Cash In", a list row menu): no For / member / item
    drop menus — hidden inputs + its details (Payment/partials/payable-details: amount, discount, net, paid, remaining).
    [data-show-if="field=value ..."]: shown only while the select has one of the values (custom.js applyConditions).
    PaymentService::create() checks the rules again (amount > 0, a closed day, a reservation inside a package, the ownership).
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $kind = old('payable', $payableKind);
@endphp

{{-- opened from a reservation / package view: the popup goes back to it after saving --}}
@if (request()->filled('payable_id'))
    <input type="hidden" name="back_to_payable" value="1">
@endif
{{-- opened from the employee's "My Shift" page (a missed payment): back to it after saving --}}
@if (request()->boolean('back_to_shift'))
    <input type="hidden" name="back_to_shift" value="1">
@endif

@if ($payable ?? null)
    <input type="hidden" name="payable" value="{{ $payableKind }}">
    <input type="hidden" name="payable_id" value="{{ $payable->getKey() }}">
    <input type="hidden" name="member_id" value="{{ $payment->member_id ?: '' }}">
    @include('paymentmodule::Payment.partials.payable-details', ['payable' => $payable, 'kind' => $payableKind])
@else
{{-- "For" decides the next fields (user 2026-10-21): Reservation → Member, then the member's reservation | Package → the
     package (its member comes with it: PaymentRequest) | Other Income → the income category (no member) --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'payable', 'label' => 'For', 'type' => 'select', 'required' => true,
        'options' => ['reservation' => 'Reservation', 'package' => 'Package', '' => 'Other Income'], 'value' => $kind])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-1" data-show-if="payable=reservation">
    {{-- server search + quick add (membermodule::partials.member-select) --}}
    @include('membermodule::partials.member-select', ['member' => $payment->member_id ? $payment->member : null, 'companies' => $companies,
        'required' => true, 'hint' => 'Search by name or mobile.'])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-1" data-show-if="payable=reservation">
    @include('layoutmodule::partials.field', ['name' => 'payable_id', 'label' => 'Reservation', 'type' => 'select', 'required' => true, 'searchable' => true, 'width' => 'xlong',
        'options' => ['' => '- Select -'] + $payableOptions, 'value' => $payment->payable_id ?: null,
        'attrs' => 'data-options-url="' . route($area . '.payments.options.payables') . '" data-parent="member_id" data-also="payable" data-none="Nothing to pay for this member"' . (empty($payableOptions) ? ' disabled' : ''),
        'hint' => 'The member\'s reservations (not inside a package: they are paid through the package), the ones still owed first.'])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-1" data-show-if="payable=package">
    @include('layoutmodule::partials.field', ['name' => 'package_id', 'label' => 'Package', 'type' => 'select', 'required' => true, 'searchable' => true, 'width' => 'xlong',
        'options' => ['' => '- Select -'] + $packageOptions,
        'hint' => 'Search by package or member name; the ones still owed first.'])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3" data-show-if="payable=">
    @include('layoutmodule::partials.field', ['name' => 'income_category_id', 'label' => 'Income Category', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $categories, 'value' => $payment->income_category_id ?: null,
        'hint' => empty($categories) ? 'No income categories yet, add them from Settings > Income Categories.' : null])
</div>
@endif

<div class="form-section mt-8">Cash In</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    {{-- Settings › General "Withholding tax" off: payments only --}}
    @if (\Modules\PaymentModule\app\Services\PaymentService::withholdingOn())
        @include('layoutmodule::partials.field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true,
            'options' => ['payment' => 'Cash In', 'withholding' => 'Withholding'], 'value' => $payment->type,
            'hint' => 'Withholding: tax withheld by the customer (a certificate instead of money).'])
    @else
        <input type="hidden" name="type" value="payment">
    @endif
    {{-- one reservation: not more than it still owes (PaymentService checks it again, also for the chosen one); a package: any amount (credit) --}}
    @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'value' => $payment->amount,
        'attrs' => 'min="0.01" step="0.01" max="' . (($payable ?? null) && $payableKind === 'reservation' ? max(0.01, (float) $payable->remaining) : 99999999) . '"',
        'hint' => ($payable ?? null) ? ($payableKind === 'reservation' ? 'Not more than the remaining ' . number_format(max(0, (float) $payable->remaining), 2) . '.' : ((float) $payable->remaining > 0 ? 'Remaining ' . number_format((float) $payable->remaining, 2) . ' — more becomes the member\'s credit.' : 'Nothing owed — the amount is added to the member\'s credit.')) : 'A reservation: not more than its remaining. A package: more becomes credit.'])
    @include('paymentmodule::Payment.partials.date-field', ['name' => 'paid_at', 'value' => $payment->paid_at])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
    <div data-show-if="type=payment">
        @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Payment Method', 'type' => 'select', 'required' => true, 'searchable' => true,
            'options' => ['' => '- Select -'] + $methods, 'value' => $payment->payment_method_id ?: null,
            'hint' => empty($methods) ? 'No payment methods yet, add them from Settings > Payment Methods.' : null])
    </div>
    @include('paymentmodule::Payment.partials.reference-field', ['value' => $payment->reference])
</div>

<div class="mt-5">
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $payment->notes])
</div>
