{{-- the payment being edited / refunded / deleted (the top of those popups): number, type, date, amount, the reservation / package No., for --}}
<dl class="detail-list">
    <div><dt>No.</dt><dd>#{{ $payment->payment_no }} <span class="badge {{ $payment->type_badge_class }} ml-1">{{ $payment->type_label }}</span></dd></div>
    <div><dt>Date / Amount</dt><dd>{{ $payment->paid_at?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }} · {{ number_format($payment->signed_amount, 2) }}</dd></div>
    <div><dt>Member</dt><dd>@include('membermodule::partials.member-link', ['member' => $payment->member])</dd></div>
    @if ($payment->payable)
        <div><dt>{{ ucfirst($payment->payable_kind) }} No.</dt><dd>#{{ $payment->payable_id }}</dd></div>
    @endif
    <div><dt>For</dt><dd>{{ $payment->payable ? ($payableName ?? '-') : ($payment->incomeCategory?->name ?? 'Other income') }}</dd></div>
</dl>
