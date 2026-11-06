@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{-- Report 3 row popup: the payments of one row (the page's filters + group), newest first; a row opens its payment (when the login may) --}}
@section('title')
    {{ $label ?? 'Payments' }}
    <div class="text-xs font-normal text-slate-500">{{ $group }} · {{ $range }}</div>
@endsection

@section('modal-size', 'xl')

@section('content')
    @php($dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT)
    @if ($payments->isEmpty())
        <div class="empty-state">No payments.</div>
    @else
        @if ($count > $payments->count())
            <div class="notice notice--warning mb-3">The newest {{ $payments->count() }} of {{ $count }} payments are listed; the total is for all {{ $count }}.</div>
        @endif
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>No.</th><th>Date</th><th>Member</th><th>For</th><th>Method</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        @php($open = $canOpen($payment))
                        <tr @class(['report-click' => $open])>
                            <td class="whitespace-nowrap">
                                @if ($open)
                                    <a href="{{ route($area . '.payments.show', $payment->id) }}" data-modal>{{ $payment->payment_no }}</a>
                                @else
                                    {{ $payment->payment_no }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ $payment->paid_at?->format($dateFormat) }}</td>
                            <td>{{ $payment->member?->name ?? '-' }}</td>
                            <td>@include('paymentmodule::Payment.partials.row-source', ['payment' => $payment, 'plain' => true])</td>
                            <td class="whitespace-nowrap">{{ $payment->type === 'withholding' ? 'Withholding' : ($payment->paymentMethod?->name ?? '-') }}</td>
                            <td class="text-right">@include('paymentmodule::Payment.partials.row-amount', ['payment' => $payment])</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <td colspan="5">Total ({{ $count }} payments · cash in {{ number_format($totals->cash_in, 2) }} − refunds {{ number_format($totals->refunds, 2) }} + withholding {{ number_format($totals->withholding, 2) }})</td>
                        <td class="text-right whitespace-nowrap">{{ number_format($totals->total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
@endsection
