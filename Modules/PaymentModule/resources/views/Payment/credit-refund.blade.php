@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Refund Credit · {{ $kind === 'package' ? 'Package' : 'Reservation' }} #{{ $payable->getKey() }}
@endsection

@section('modal-size', 'lg')

{{-- a reservation's / package's credit given back (2026-11-18, PaymentService::refundCredit()): ONE refund linked to it (not to a
     single cash in), in the refunder's own open shift; reason required; the server checks the credit again --}}
@section('content')
    <form method="POST" action="{{ route($area . '.payments.credit-refund.store', [$kind, $payable->getKey()]) }}" data-ajax
        data-confirm="A refund will be added to your shift: the money goes back to the member from your drawer."
        data-confirm-title="Refund this credit?"
        data-confirm-button="Refund"
        data-confirm-variant="danger">
        @csrf

        <dl class="detail-list">
            <div><dt>{{ $kind === 'package' ? 'Package' : 'Reservation' }}</dt><dd>{{ $payableName }}</dd></div>
            <div><dt>Member</dt><dd>{{ $payable->member?->name ?? '-' }}</dd></div>
            <div><dt>Due</dt><dd>{{ number_format($payable->payableAmount(), 2) }}</dd></div>
            <div><dt>Paid</dt><dd>{{ number_format($payable->paid_amount, 2) }}</dd></div>
            <div><dt>Credit</dt><dd class="font-medium text-primary">{{ number_format($credit, 2) }}</dd></div>
        </dl>

        @if ($credit <= 0)
            <div class="notice notice--warning mt-5">There is no credit to refund (nothing paid beyond the due).</div>
        @endif

        <div class="form-section mt-6">Refund</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true,
                'value' => $credit > 0 ? $credit : null,
                'attrs' => 'min="0.01" step="0.01" max="' . max(0, $credit) . '"',
                'hint' => 'Up to ' . number_format(max(0, $credit), 2) . ' (the credit).'])
            @include('paymentmodule::Payment.partials.date-field', ['name' => 'paid_at'])
            @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Paid Back By', 'type' => 'select', 'required' => true, 'searchable' => true,
                'options' => ['' => '- Select -'] + $methods])
        </div>
        <div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
            @include('paymentmodule::Payment.partials.reference-field', ['hint' => 'Cash: empty = "Credit refund".'])
        </div>
        <div class="mt-5">
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true,
                'attrs' => 'maxlength="255"', 'hint' => 'Why the money is given back (shown in the Daily Summary).'])
        </div>
        <div class="mt-5">
            @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.' . $kind . 's.show', $payable->getKey())])
    </form>
@endsection
