{{--
    "Reference no." (2026-10-27): required for the methods with "Needs Reference No." (every method but cash), never used twice
    for the same method (PaymentService / ExpenseService check it). $value, $hint (optional, added after the rule).
--}}
@php($referenceNames = app(\Modules\PaymentModule\app\Services\PaymentMethodService::class)->referenceNames(Auth::user()->userable->ownerAccountId()))
@include('layoutmodule::partials.field', ['name' => 'reference', 'label' => 'Reference No.', 'value' => $value ?? null, 'attrs' => 'maxlength="255"',
    'hint' => trim(($referenceNames ? 'Required for ' . implode(', ', $referenceNames) . ' (the transfer / transaction no., never twice).' : '') . ' ' . ($hint ?? ''))])
