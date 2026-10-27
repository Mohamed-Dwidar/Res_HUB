{{--
    The Date of a NEW cash in / refund / cash out (2026-10-27, fraud prevention): employees can't choose it — the business date of
    the recording time, read only (PaymentService::recordDate() forces it on the server too); the account owner may set another
    one (reports only: the row always joins the recorder's open shift). $name: paid_at | spent_at, $value: the owner's default.
--}}
@php
    $owner = Auth::user()->userable instanceof \Modules\AccountModule\app\Models\Account;
    $businessDate = \Modules\AccountModule\app\Models\Account::current()?->businessDate() ?? today();
@endphp
@include('layoutmodule::partials.field', ['name' => $name, 'label' => 'Date', 'type' => 'date', 'required' => true,
    'value' => ($owner && !empty($value) ? $value : $businessDate)->format('Y-m-d'),
    'attrs' => $owner ? '' : 'readonly',
    'hint' => $owner ? 'Another date = reports only; it stays in your open shift (an earlier date shows "Late entry").' : 'Today (the recording date).'])
