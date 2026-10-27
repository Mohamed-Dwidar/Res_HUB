{{--
    The payments of a closed shift (the shift popup), newest first, at most ShiftClosingService::POPUP_LIMIT (+ a note): the same columns and cells as the Payments list
    (Payment/partials/row-*): Member | For | Date (time) | Method | Amount | row menu. Rendered here (a popup has no DataTables).
    $payments: with payable (+ space / unit.color / plan of a reservation), member, paymentMethod, incomeCategory
--}}

@if (isset($closing) && $closing->count > $payments->count())
    <div class="mb-2 text-xs text-slate-500">The newest {{ $payments->count() }} of {{ number_format($closing->count) }} cash in entries.</div>
@endif
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr><th>Member</th><th>For</th><th>Date</th><th>Method</th><th>Amount</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr id="row-{{ $payment->id }}">
                    <td>@include('paymentmodule::Payment.partials.row-member')</td>
                    <td>@include('paymentmodule::Payment.partials.row-source')</td>
                    <td class="whitespace-nowrap">@include('paymentmodule::Payment.partials.record-date', ['record' => $payment])</td>
                    <td>{{ $payment->paymentMethod?->name ?? '-' }}</td>
                    <td class="whitespace-nowrap">@include('paymentmodule::Payment.partials.row-amount')</td>
                    <td>@include('paymentmodule::Payment.partials.row-actions')</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-state">No cash in in this shift.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
