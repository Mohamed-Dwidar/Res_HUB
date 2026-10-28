@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{-- "Cash In #N" / "Refund #N" / "Withholding #N" --}}
    {{ $payment->type_label }} #{{ $payment->payment_no }}
@endsection

@section('modal-size', 'lg')

@section('actions')
    <a href="{{ route($area . '.payments.receipt', $payment->id) }}" target="_blank" class="btn btn-secondary"><i data-lucide="printer"></i> Receipt</a>
    {{-- refund: the account and employees (into the refunder's shift); edit: the owner, or an employee for their own cash in
         while their shift is open (PaymentService::canEdit()); delete: the account owner only --}}
    @if ($payment->type === 'payment')
        <a href="{{ route($area . '.payments.refund', $payment->id) }}" data-modal class="btn btn-secondary"><i data-lucide="undo-2"></i> Refund</a>
    @endif
    @if (app(\Modules\PaymentModule\app\Services\PaymentService::class)->canEdit($payment, Auth::user()))
        <a href="{{ route($area . '.payments.edit', $payment->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @endif
    @if ($area === 'account')
        <a href="{{ route('account.payments.delete', $payment->id) }}" data-modal class="btn btn-danger"><i data-lucide="trash-2"></i> Delete</a>
    @endif
@endsection

@section('content')
    <dl class="detail-list">
        <div><dt>Date</dt><dd>{{ $payment->paid_at?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}</dd></div>
        <div><dt>Amount</dt><dd class="font-medium {{ $payment->type === 'refund' ? 'text-danger' : 'text-success' }}">{{ number_format($payment->signed_amount, 2) }}</dd></div>
        <div><dt>Payment Method</dt><dd>{{ $payment->paymentMethod?->name ?? '-' }}</dd></div>
        <div><dt>Reference</dt><dd>{{ $payment->reference ?: '-' }}</dd></div>
        @if ($payment->type === 'withholding')
            <div><dt>Certificate</dt><dd>
                @if ($payment->withholdingCertificate)
                    @if ($area === 'account' && \Modules\PaymentModule\app\Services\PaymentService::withholdingOn())
                        <a href="{{ route('account.withholding-certificates.show', $payment->withholding_certificate_id) }}" data-modal class="font-medium">{{ $payment->withholdingCertificate->certificate_no }}</a>
                    @else
                        {{ $payment->withholdingCertificate->certificate_no }}
                    @endif
                @else
                    <span class="badge badge-status-warning">No certificate yet</span>
                @endif
            </dd></div>
        @endif
    </dl>

    <div class="form-section">&nbsp;</div>

    <dl class="detail-list">
        <div><dt>Member</dt><dd>{{ $payment->member?->name ?? '-' }}</dd></div>
        <div><dt>Member Phone</dt><dd>{{ $payment->member?->phone ?? '-' }}</dd></div>
        {{-- the reservation / package number: its own field (opens it), not inside "For" --}}
        @if ($payment->payable)
            <div><dt>{{ ucfirst($payment->payable_kind) }} No.</dt><dd><a href="{{ route($area . '.' . $payment->payable_kind . 's.show', $payment->payable_id) }}" data-modal class="font-medium">#{{ $payment->payable_id }}</a></dd></div>
        @endif
        <div class="wide"><dt>For</dt><dd>
            {{-- once: a reservation = (unit color) Space › Unit (opens it) · its period; a package = its name (opens it) · its period --}}
            @if ($payment->payable)
                <div class="flex flex-wrap items-center gap-2">
                    @include('paymentmodule::Payment.partials.row-source', ['inline' => true])
                    <span>&middot; {{ $payment->payable->period }}</span>
                </div>
            @else
                @include('paymentmodule::Payment.partials.row-source')
            @endif
        </dd></div>
        @if ($payment->payable)
            {{-- the payable now (after all its payments) --}}
            <div><dt>Due</dt><dd>{{ number_format($payment->payable->payableAmount(), 2) }}</dd></div>
            <div><dt>Paid / Remaining</dt><dd>
                {{ number_format($payment->payable->paid_amount, 2) }} / {{ number_format($payment->payable->remaining, 2) }}
                <span class="badge {{ $payment->payable->payment_status_badge_class }} ml-1">{{ $payment->payable->payment_status_label }}</span>
            </dd></div>
        @endif
    </dl>

    <div class="form-section">&nbsp;</div>

    <dl class="detail-list">
        @if ($payment->type === 'refund')
            <div><dt>Refund Of</dt><dd>
                @if ($payment->refundOf)
                    <a href="{{ route($area . '.payments.show', $payment->refund_of) }}" data-modal class="font-medium">#{{ $payment->refundOf->payment_no }}</a>
                    ({{ number_format($payment->refundOf->amount, 2) }})
                @else
                    -
                @endif
            </dd></div>
            <div><dt>Reason</dt><dd>{{ $payment->reason ?: '-' }}</dd></div>
        @endif
        {{-- its shift: the collector's open shift, or a closed one (the account opens it) --}}
        <div><dt>Shift</dt><dd>
            @if (!$payment->shift_closing_id)
                <span class="badge badge-status-warning">Open shift</span>
            @elseif ($area === 'account')
                <a href="{{ route('account.shifts.show', $payment->shift_closing_id) }}" data-modal><span class="badge badge-status-success"><i data-lucide="lock" class="inline h-3 w-3"></i> Closed</span></a>
            @else
                <span class="badge badge-status-success"><i data-lucide="lock" class="inline h-3 w-3"></i> Closed</span>
            @endif
        </dd></div>
        <div class="wide"><dt>Notes</dt><dd class="whitespace-pre-line">{{ $payment->notes ?: '-' }}</dd></div>
        <div class="wide">
            <dd class="whitespace-pre-line">
                Added by <b>{{ $payment->creatable?->userable?->displayName() ?? $payment->creatable?->email ?? '-' }}</b>
                |
                At {{ $payment->created_at->format('d-m-Y h:i A') }}
            </dd>
        </div>
    </dl>

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.payments.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Payments</a>
        </div>
    @endunless
@endsection
