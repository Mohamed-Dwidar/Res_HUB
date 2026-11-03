{{--
    "Reference no.": refunds = required for the methods with "Needs Reference No." (every method but cash), never used twice for
    the same method (PaymentService checks it); cash in (2026-11-01) / cash out (2026-11-03) = optional, may repeat ($optional).
    $value, $hint (optional, added after the rule).
--}}
@php($referenceNames = empty($optional) ? app(\Modules\PaymentModule\app\Services\PaymentMethodService::class)->referenceNames(Auth::user()->userable->ownerAccountId()) : [])
@include('layoutmodule::partials.field', ['name' => 'reference', 'label' => 'Reference No.', 'value' => $value ?? null, 'attrs' => 'maxlength="255"',
    'hint' => trim((!empty($optional) ? 'Optional (the transfer / transaction no. when known).' : ($referenceNames ? 'Required for ' . implode(', ', $referenceNames) . ' (the transfer / transaction no., never twice).' : '')) . ' ' . ($hint ?? ''))])
