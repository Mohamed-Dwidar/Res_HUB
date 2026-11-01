{{--
    "For" + what it pays, shared by Add Cash In (Payment/partials/form, not opened for one reservation / package) and Edit (cash in /
    withholding, 2026-11-01): For (reservation | package | other income) → the member + the member's reservation (ajax, custom.js
    [data-payment-form]) | the package | the income category. $payment, $kind, $payableOptions, $packageOptions, $categories, $companies.
--}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
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
        'options' => ['' => '- Select -'] + $payableOptions, 'value' => $payment->payable_kind === 'reservation' ? $payment->payable_id : null,
        'attrs' => 'data-options-url="' . route($area . '.payments.options.payables') . '" data-parent="member_id" data-also="payable" data-none="Nothing to pay for this member"' . (empty($payableOptions) ? ' disabled' : ''),
        'hint' => 'The member\'s reservations (not inside a package: they are paid through the package), the ones still owed first.'])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-1" data-show-if="payable=package">
    @include('layoutmodule::partials.field', ['name' => 'package_id', 'label' => 'Package', 'type' => 'select', 'required' => true, 'searchable' => true, 'width' => 'xlong',
        'options' => ['' => '- Select -'] + $packageOptions, 'value' => $payment->payable_kind === 'package' ? $payment->payable_id : null,
        'hint' => 'Search by package or member name; the ones still owed first.'])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3" data-show-if="payable=">
    @include('layoutmodule::partials.field', ['name' => 'income_category_id', 'label' => 'Income Category', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $categories, 'value' => $payment->income_category_id ?: null,
        'hint' => empty($categories) ? 'No income categories yet, add them from Settings > Income Categories.' : null])
</div>
