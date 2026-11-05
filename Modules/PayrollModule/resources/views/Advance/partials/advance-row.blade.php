{{--
    One advance (the Advances & Adjustments tab): a compact row (Advance #N · date · Amount · Remaining · Monthly installment ·
    Next deduction · status + menu) and a "Details" toggle (planned schedule, paid from + its company Cash Out, reason, moves).
    $advance, $advanceService, $money, $isOwner, $staff, $area.
--}}
@php($remaining = $advanceService->remaining($advance))
@php($schedule = $advanceService->schedule($advance))
@php($next = $schedule[0] ?? null)
<div @class(['advance-row', 'advance-row--closed' => $advance->status !== 'open'])>
    <div class="advance-row__main">
        <div class="advance-row__title">
            <b>Advance #{{ $advance->advance_no }}</b>
            <span class="text-slate-500">{{ $advance->given_on->format('d-m-Y') }}</span>
        </div>
        <div class="advance-row__cell"><span>Amount</span><b>{{ $money($advance->amount) }}</b></div>
        <div class="advance-row__cell"><span>Remaining</span><b @class(['text-primary' => $advance->status === 'open'])>{{ $advance->status === 'cancelled' ? '-' : $money($remaining) }}</b></div>
        <div class="advance-row__cell"><span>Monthly</span><b>{{ $money($next['installment'] ?? $advance->installment_amount) }}</b></div>
        <div class="advance-row__cell"><span>Next deduction</span><b>{{ $next ? $next['month']->format('M Y') . ': ' . $money($next['planned']) : '-' }}</b></div>
        <div class="advance-row__status">
            <span class="badge {{ ['open' => 'badge-status-warning', 'settled' => 'badge-status-success', 'cancelled' => 'badge-status-slate'][$advance->status] ?? '' }}">{{ $advance->status_label }}</span>
            @if ($advance->status === 'open')
                @include('layoutmodule::partials.row-menu', ['links' => [
                    ['label' => 'Cash Repayment', 'icon' => 'banknote', 'url' => route($area . '.payroll.advances.moves.create', [$staff->id, $advance->id, 'repay']), 'modal' => true],
                    ['label' => 'Change Installment', 'icon' => 'pencil', 'url' => route($area . '.payroll.advances.moves.create', [$staff->id, $advance->id, 'change']), 'modal' => true],
                    ['label' => 'Cancel', 'icon' => 'x-circle', 'url' => route($area . '.payroll.advances.cancel.form', [$staff->id, $advance->id]), 'modal' => true],
                ]])
            @endif
        </div>
    </div>

    <details class="advance-details">
        <summary>Details</summary>
        <dl class="info-rows">
            <div><dt>Paid from</dt><dd>{{ $advance->paymentMethod?->name ?? '-' }}</dd></div>
            <div><dt>Company Cash Out</dt><dd>
                @if ($advance->expense)
                    <a href="{{ route($area . '.expenses.show', $advance->expense->id) }}" data-modal>#{{ $advance->expense->expense_no }}</a>{{ $advance->expense->trashed() ? ' · voided' : '' }}
                @else
                    -
                @endif
            </dd></div>
            <div><dt>Installments</dt><dd>{{ $advance->installments }} × {{ $money($advance->installment_amount) }} from {{ $advance->first_period->format('M Y') }}</dd></div>
            @if ($advance->reason)
                <div><dt>Reason</dt><dd>{{ $advance->reason }}</dd></div>
            @endif
            <div><dt>Given by</dt><dd>{{ $advance->creatable?->userable?->displayName() ?? '-' }}</dd></div>
            @if ($advance->status === 'cancelled')
                <div><dt>Cancelled</dt><dd>{{ $advance->cancelled_at?->format('d-m-Y h:i A') }} · {{ $advance->cancel_reason }}</dd></div>
            @endif
        </dl>

        @if ($schedule)
            <div class="advance-details__label">Planned deductions (by payroll)</div>
            <div class="table-wrap">
                <table class="data-table salary-history">
                    <thead><tr><th>Month</th><th class="text-right">Deduction</th><th class="text-right">Left after</th></tr></thead>
                    <tbody>
                        @foreach ($schedule as $row)
                            <tr>
                                <td>{{ $row['month']->format('F Y') }}</td>
                                <td class="text-right">{{ $money($row['planned']) }}</td>
                                <td class="text-right text-slate-500">{{ $money($row['left']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($advance->moves->isNotEmpty())
            <div class="advance-details__label">History</div>
            <div class="table-wrap">
                <table class="data-table salary-history">
                    <tbody>
                        @foreach ($advance->moves->sortByDesc('id') as $move)
                            <tr @class(['row-muted' => $move->trashed()])>
                                <td class="whitespace-nowrap">{{ $move->created_at?->format('d-m-Y') }}</td>
                                <td class="whitespace-nowrap">{{ $move->type_label }}@if ($move->payment) <span class="text-xs text-slate-500">· Cash In #{{ $move->payment->payment_no }}</span>@endif</td>
                                <td class="text-right whitespace-nowrap font-medium">{{ $money($move->amount) }}</td>
                                <td class="whitespace-nowrap">{{ $move->period?->format('M Y') ?? '-' }}</td>
                                <td>
                                    {{ $move->reason ?: '-' }}
                                    @if ($move->trashed()) <div class="text-xs text-slate-500">Cancelled: {{ $move->delete_reason }}</div> @endif
                                </td>
                                <td class="whitespace-nowrap text-slate-500">{{ $move->creatable?->userable?->displayName() ?? '-' }}</td>
                                <td class="whitespace-nowrap">
                                    @php($canCancel = !$move->trashed() && $advance->status !== 'cancelled' && ($move->type === 'change' || ($move->type === 'repay' && $isOwner)))
                                    @if ($canCancel)
                                        <a href="{{ route($area . '.payroll.advances.moves.cancel.form', [$staff->id, $advance->id, $move->id]) }}" data-modal class="text-danger text-sm">Cancel</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </details>
</div>
