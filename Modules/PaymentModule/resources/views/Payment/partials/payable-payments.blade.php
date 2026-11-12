{{--
    The payments block of a reservation / package view.
    @include('paymentmodule::Payment.partials.payable-payments', ['payable' => $reservation, 'kind' => 'reservation'])
    $kind: reservation | package (PaymentService::PAYABLES). A reservation inside a package is paid through the package (link to it).
    Employees see ALL the payments of the item with the collector (to answer a customer / ask the colleague), payments-plan.md §2b.
    "Add payment" opens the payment form for it in the popup; after saving, the popup shows this view again (json "modal").
    $summary (optional, default true): false = no Due / Paid / Remaining / Status lines (the reservation view shows them above).
    $methods (optional, the payment methods): "+ Cash In" opens the Add Cash In fields in this view (Payment/partials/inline-form)
    instead of the popup page (the reservation view, 2026-10-21). Only while it still owes for a reservation (never more than the remaining); a package always (paid beyond its due = credit).
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $paymentService = app(\Modules\PaymentModule\app\Services\PaymentService::class);
    // roles (docs/roles-plan.md, R0): "+ Cash In" only with payments.create
    $canAdd = $payable->canTakePayments() && ($kind === 'package' || $payable->remaining > 0) && Gate::allows('payments.create');
@endphp

<div class="form-section mt-8 payments-block__head">
    {{-- "Cash In" (2026-10-14): its payments, withholding and refunds --}}
    <span>Cash In</span>
    @if ($canAdd && isset($methods))
        <button type="button" data-reveal="#cash-in-form" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Cash In</button>
    @elseif ($canAdd)
        <a href="{{ route($area . '.payments.create', ['payable' => $kind, 'payable_id' => $payable->getKey()]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Cash In</a>
    @endif
</div>

{{-- $methods given (the reservation view): the Add Cash In fields here, hidden until "+ Cash In" / "Pay" --}}
@if ($canAdd && isset($methods))
    @include('paymentmodule::Payment.partials.inline-form')
@endif

@if (!$payable->canTakePayments())
    <div class="text-slate-500">
        Paid through the package
        @if ($payable->package)
            <a href="{{ route($area . '.packages.show', $payable->package_id) }}" data-modal class="font-medium"><i data-lucide="package" class="inline h-3 w-3"></i> {{ $payable->package->name }}</a>.
        @endif
    </div>
@else
    @if ($summary ?? true)
    <dl class="detail-list">
        <div><dt>Due</dt><dd>{{ number_format($payable->payableAmount(), 2) }}
            @if (method_exists($payable, 'extrasTotal') && $payable->extrasTotal() != 0)
                <span class="text-xs text-slate-500">(net {{ number_format($payable->payableAmount() - $payable->extrasTotal(), 2) }} + extras {{ number_format($payable->extrasTotal(), 2) }})</span>
            @endif
        </dd></div>
        <div><dt>Paid</dt><dd>{{ number_format($payable->paid_amount, 2) }}</dd></div>
        <div><dt>{{ $payable->remaining < 0 ? 'Credit' : 'Remaining' }}</dt><dd class="font-medium {{ $payable->remaining > 0 ? 'text-danger' : 'text-success' }}">{{ number_format(abs($payable->remaining), 2) }}</dd></div>
        <div><dt>Status</dt><dd><span class="badge {{ $payable->payment_status_badge_class }}">{{ $payable->payment_status_label }}</span></dd></div>
    </dl>
    @endif

    @php($payments = $payable->paymentsList())
    @if ($payments->isNotEmpty())
        {{-- table-wrap--tips: the (i) notes' tooltips are not clipped (computer screens) --}}
        <div class="table-wrap table-wrap--tips mt-4">
            <table class="data-table">
                <thead>
                    {{-- the order the desk reads them (user 2026-10-21): Amount, Date, No., Method, Type, Collected by, print / edit / delete;
                         Amount, Date and No. open the payment (2026-10-28) --}}
                    <tr>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>No.</th>
                        <th>Method</th>
                        <th>Type</th>
                        <th>Collected by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        @php($showUrl = route($area . '.payments.show', $payment->id))
                        {{-- a settling Cash In (Offset, 2026-11-12): its Cash Out; opened when this login may see it (expenses.view + CashScope, as the reports) --}}
                        @php($settledBy = $payment->offset_expense_id ? $payment->offsetExpense : null)
                        @php($cashOutUrl = $settledBy && !$settledBy->trashed() && Gate::allows('expenses.view') && (app(\Modules\PaymentModule\app\Services\CashScope::class)->expensesCollector() === null || (int) $settledBy->creatable_id === (int) Auth::id()) ? route($area . '.expenses.show', $settledBy->id) : null)
                        <tr>
                            <td><a href="{{ $showUrl }}" data-modal class="font-medium {{ $payment->type === 'refund' ? 'text-danger' : '' }}">{{ number_format($payment->signed_amount, 2) }}</a></td>
                            <td class="whitespace-nowrap"><a href="{{ $showUrl }}" data-modal>@include('paymentmodule::Payment.partials.record-date', ['record' => $payment, 'time' => false])</a></td>
                            {{-- No. = the Cash Out's for a settling Cash In (user 2026-11-12) --}}
                            <td>
                                @if ($settledBy)
                                    @if ($cashOutUrl)
                                        <a href="{{ $cashOutUrl }}" data-modal class="font-medium" title="Cash Out">#{{ $settledBy->expense_no }}</a>
                                    @else
                                        <span class="font-medium" title="Cash Out">#{{ $settledBy->expense_no }}</span>
                                    @endif
                                @else
                                    <a href="{{ $showUrl }}" data-modal class="font-medium">#{{ $payment->payment_no }}</a>
                                @endif
                            </td>
                            <td>{{ $payment->paymentMethod?->name ?? '-' }}</td>
                            {{-- (i) = its note on hover (2026-11-12, user); a settling Cash In (Offset): "Settles Cash Out" + the Cash Out's
                                 category · item + its note --}}
                            @php($tip = $settledBy
                                ? trim(($settledBy->expenseCategory?->name ?? '-') . ': ' . $settledBy->item . ($settledBy->notes ? ' — ' . $settledBy->notes : ''))
                                : trim(($payment->type === 'refund' && $payment->reason ? 'Reason: ' . $payment->reason . ($payment->notes ? ' — ' : '') : '') . (string) $payment->notes))
                            <td class="whitespace-nowrap">
                                <span class="badge {{ $payment->type_badge_class }}">{{ $payment->type_label }}</span>
                                @if ($tip !== '')
                                    <span class="info-tip info-tip--left" tabindex="0" data-tip="{{ $tip }}" aria-label="{{ $tip }}"><i data-lucide="info"></i></span>
                                @endif
                                {{-- under the type badge (user 2026-11-12) --}}
                                {{-- + its number, opening the Cash Out when this login may see it --}}
                                @if ($settledBy)
                                    <div class="mt-1">
                                        @if ($cashOutUrl)
                                            <a href="{{ $cashOutUrl }}" data-modal class="badge badge-status-primary">Settles Cash Out #{{ $settledBy->expense_no }}</a>
                                        @else
                                            <span class="badge badge-status-primary">Settles Cash Out #{{ $settledBy->expense_no }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td>{{ $payment->creatable?->userable?->displayName() ?? $payment->creatable?->email ?? '-' }}</td>
                            {{-- the receipt (new tab); edit = the owner, or an employee for their own cash in of their open shift
                                 (PaymentService::canEdit()); delete = the account owner only (popups, then back to this view) --}}
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
    @else
        <div class="mt-3 text-slate-500">No cash in yet.</div>
    @endif
@endif
