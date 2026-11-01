{{--
    The Cash In block of the member view (2026-11-01): the owner AND employees see EVERY payment of the member (cash in, withholding,
    refunds, other income with this member) whoever recorded it, with "Collected by" — like the reservation / package views;
    the Payments list and My Shift keep their rule (employees: their own rows). Totals = the member's reservations paid on their
    own + packages (PaymentService::memberSummary()). Edit: PaymentService::canEdit(); delete: the account owner.
    @include('paymentmodule::Payment.partials.member-payments', ['member' => $member])
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $paymentService = app(\Modules\PaymentModule\app\Services\PaymentService::class);
    $summary = $paymentService->memberSummary($member->account_id, $member->id);
@endphp

<div class="form-section mt-8 payments-block__head">
    <span>Cash In</span>
    {{-- the full list (the account: the Payments page; employees' list = their own rows of one day) --}}
    @if ($area === 'account' && $summary['count'] > $summary['payments']->count())
        <a href="{{ route('account.payments.index', ['type' => 'in', 'member_id' => $member->id]) }}" target="_blank" class="btn btn-secondary btn-sm">All {{ $summary['count'] }}</a>
    @endif
</div>

<div class="money-box">
    <div class="money-box__item money-box__item--due" title="Reservations paid on their own + packages">
        <span>Due</span><b>{{ number_format($summary['due'], 2) }}</b>
    </div>
    <div class="money-box__item money-box__item--paid">
        <span>Paid</span><b>{{ number_format($summary['paid'], 2) }}</b>
    </div>
    <div class="money-box__item {{ $summary['remaining'] > 0 ? 'money-box__item--owed' : 'money-box__item--paid' }}" title="What the member still owes">
        <span>Remaining</span><b>{{ number_format($summary['remaining'], 2) }}</b>
    </div>
    @if ($summary['credit'] < 0)
        <div class="money-box__item money-box__item--credit" title="Paid beyond the due (cancelled / over paid)">
            <span>Credit</span><b>{{ number_format(abs($summary['credit']), 2) }}</b>
        </div>
    @endif
</div>

@if ($summary['payments']->isNotEmpty())
    <div class="table-wrap mt-4">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>No.</th>
                    <th>For</th>
                    <th>Method</th>
                    <th>Type</th>
                    <th>Collected by</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['payments'] as $payment)
                    @php($showUrl = route($area . '.payments.show', $payment->id))
                    <tr>
                        <td><a href="{{ $showUrl }}" data-modal class="font-medium {{ $payment->type === 'refund' ? 'text-danger' : '' }}">{{ number_format($payment->signed_amount, 2) }}</a></td>
                        <td class="whitespace-nowrap"><a href="{{ $showUrl }}" data-modal>@include('paymentmodule::Payment.partials.record-date', ['record' => $payment, 'time' => false])</a></td>
                        <td><a href="{{ $showUrl }}" data-modal class="font-medium">#{{ $payment->payment_no }}</a></td>
                        <td>@include('paymentmodule::Payment.partials.row-source')</td>
                        <td>{{ $payment->paymentMethod?->name ?? '-' }}</td>
                        <td><span class="badge {{ $payment->type_badge_class }}">{{ $payment->type_label }}</span></td>
                        <td>{{ $payment->creatable?->userable?->displayName() ?? $payment->creatable?->email ?? '-' }}</td>
                        <td class="whitespace-nowrap">
                            <span class="payments-block__icons">
                                <a href="{{ route($area . '.payments.receipt', $payment->id) }}" target="_blank" title="Receipt"><i data-lucide="printer" class="h-4 w-4"></i></a>
                                @if ($paymentService->canEdit($payment, Auth::user()))
                                    <a href="{{ route($area . '.payments.edit', $payment->id) }}" data-modal title="Edit"><i data-lucide="pencil" class="h-4 w-4"></i></a>
                                @endif
                                @if ($area === 'account')
                                    <a href="{{ route('account.payments.delete', $payment->id) }}" data-modal title="Delete" class="text-danger"><i data-lucide="trash-2" class="h-4 w-4"></i></a>
                                @endif
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($summary['count'] > $summary['payments']->count())
        <div class="mt-2 text-xs text-slate-500">The newest {{ $summary['payments']->count() }} of {{ $summary['count'] }}.</div>
    @endif
@else
    <div class="mt-3 text-slate-500">No cash in yet.</div>
@endif
