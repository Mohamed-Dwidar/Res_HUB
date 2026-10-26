@php
    $dateValue = $date->format('Y-m-d');
    $dateText = $date->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT);
    $isAccount = fn ($user) => $user?->userable instanceof \Modules\AccountModule\app\Models\Account;
    // the Withholding column: hidden when Withholding tax is off and none of the shifts has any
    $showWithholding = \Modules\PaymentModule\app\Services\PaymentService::withholdingOn()
        || $openPeriods->sum('withholding_total') > 0 || $shifts->sum('withholding_total') > 0;
@endphp
@extends('layoutmodule::account.main')

{{--
    The Daily Summary (docs/payments-plan.md §2b, 2026-10-13): read only, no closing / lock (the shift is the only closing).
    The date picker reloads it with ?date=. Long open shifts (employees, whatever their dates) | the shifts holding payments /
    expenses of this day (open → Close on Behalf / Close My Shift, closed → View) | the day's totals (per method: payments - refunds -
    expenses = net, per collector) | its refunds (no Cash Out list: user 2026-10-26, the shifts / totals have the amounts).
--}}
@section('title')
    Daily Summary {{ $dateText }}
@endsection

@section('actions')
    <form method="GET" action="{{ route('account.daily-summary.index') }}" class="filter-bar">
        <input type="date" name="date" value="{{ $dateValue }}" class="{{ config('layoutmodule.form.input') }} field-short" aria-label="Day">
        <button type="submit" class="btn btn-secondary"><i data-lucide="calendar-search"></i> Show</button>
    </form>
    <a href="{{ route('account.shift.index') }}" class="btn btn-secondary"><i data-lucide="lock"></i> My Shift</a>
@endsection

@section('content')
    {{-- employees' shifts open longer than the reminder time, with their "still working" answers --}}
    @if ($longOpen->isNotEmpty())
        <div class="form-section">Long Open Shifts</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Employee</th><th>Open Since</th><th>Cash In</th><th>Collected</th><th>"Still Working"</th><th></th></tr></thead>
                <tbody>
                    @foreach ($longOpen as $userId => $period)
                        <tr>
                            <td>{{ $period['user']?->userable?->displayName() ?? 'User #' . $userId }}</td>
                            <td class="whitespace-nowrap">{{ $period['since']->format('d-m-Y h:i A') }} <span class="text-xs text-slate-500">({{ $period['since']->diffForHumans(null, true) }})</span></td>
                            <td>{{ $period['count'] }}</td>
                            <td>{{ number_format($period['cash_total'], 2) }}</td>
                            <td class="{{ $period['expenses_total'] ? 'text-danger' : '' }}">{{ $period['expenses_total'] ? number_format(-$period['expenses_total'], 2) : '0.00' }}</td>
                            <td>
                                {{ $period['answers']->count() }}
                                @foreach ($period['answers'] as $answer)
                                    <div class="text-xs text-slate-500">{{ $answer->created_at->format('d-m-Y h:i A') }}: {{ $answer->reason ?: $answer->action_label }}</div>
                                @endforeach
                            </td>
                            <td><a href="{{ route('account.shifts.confirm', ['user_id' => $userId, 'date' => $dateValue]) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="lock"></i> Close on Behalf</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- the shifts holding payments of this day --}}
    <div class="form-section {{ $longOpen->isNotEmpty() ? 'mt-8' : '' }}">Shifts</div>
    @if ($openPeriods->isEmpty() && $shifts->isEmpty())
        <div class="text-slate-500">No cash in or cash out on this day.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                {{-- per shift (2026-10-26): Cash In (payments) | Refunds | Cash Out (expenses) | Net = in − refunds − out, the colors of
                     Shift/partials/totals (custom.css .money-*); amounts only --}}
                <thead><tr><th>Collected By</th><th>Status</th><th>From → To</th><th>Cash In</th><th>Refunds</th><th>Cash Out</th><th>Net</th>@if ($showWithholding)<th>Withholding</th>@endif<th></th></tr></thead>
                <tbody>
                    @foreach ($openPeriods as $userId => $period)
                        @php
                            $rowIn = $period['cash_total'] + $period['refunds_total'];
                        @endphp
                        <tr>
                            <td>{{ $period['user']?->userable?->displayName() ?? 'User #' . $userId }}</td>
                            <td><span class="badge badge-status-warning">Open</span></td>
                            <td class="whitespace-nowrap">{{ $period['since']->format('d-m-Y h:i A') }} → now</td>
                            <td><span class="{{ $rowIn ? 'money-in' : 'money-zero' }}">{{ number_format($rowIn, 2) }}</span></td>
                            <td class="{{ $period['refunds_total'] ? 'money-out' : 'money-zero' }}">{{ number_format(-$period['refunds_total'], 2) }}</td>
                            <td><span class="{{ $period['expenses_total'] ? 'money-out' : 'money-zero' }}">{{ number_format(-$period['expenses_total'], 2) }}</span></td>
                            <td class="money-net{{ $period['net_total'] < 0 ? ' money-net--negative' : '' }}">{{ number_format($period['net_total'], 2) }}</td>
                            @if ($showWithholding)
                                <td class="{{ $period['withholding_total'] ? 'money-withholding' : 'money-zero' }}">{{ number_format($period['withholding_total'], 2) }}</td>
                            @endif
                            <td>
                                @if ($isAccount($period['user']))
                                    <a href="{{ route('account.shift.confirm') }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="lock"></i> Close My Shift</a>
                                @else
                                    <a href="{{ route('account.shifts.confirm', ['user_id' => $userId, 'date' => $dateValue]) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="lock"></i> Close on Behalf</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($shifts as $shift)
                        @php
                            // closings saved before 2026-10-13 have no 'refunds' total: the per-method refunds
                            $rowRefunds = (float) ($shift->totals['refunds']['total'] ?? collect($shift->totals['methods'] ?? [])->sum('refunds'));
                            $rowIn = $shift->cash_total + $rowRefunds;
                        @endphp
                        <tr>
                            <td>{{ $shift->user?->userable?->displayName() ?? 'User #' . $shift->user_id }}</td>
                            <td>@include('paymentmodule::Shift.partials.status', ['closing' => $shift])</td>
                            <td>@include('paymentmodule::Shift.partials.period', ['closing' => $shift])</td>
                            <td><span class="{{ $rowIn ? 'money-in' : 'money-zero' }}">{{ number_format($rowIn, 2) }}</span></td>
                            <td class="{{ $rowRefunds ? 'money-out' : 'money-zero' }}">{{ number_format(-$rowRefunds, 2) }}</td>
                            <td><span class="{{ $shift->expenses_total ? 'money-out' : 'money-zero' }}">{{ number_format(-$shift->expenses_total, 2) }}</span></td>
                            <td class="money-net{{ $shift->net_total < 0 ? ' money-net--negative' : '' }}">{{ number_format($shift->net_total, 2) }}</td>
                            @if ($showWithholding)
                                <td class="{{ $shift->withholding_total ? 'money-withholding' : 'money-zero' }}">{{ number_format($shift->withholding_total, 2) }}</td>
                            @endif
                            <td><a href="{{ route('account.shifts.show', [$shift->id, 'date' => $dateValue]) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="eye"></i> View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-2 text-xs text-slate-500">A shift's totals are for the whole shift (it can hold cash in / cash out of other days); the day totals below are for this day only.</div>
    @endif

    <div class="form-section mt-8">Day Totals</div>
    @include('paymentmodule::DailySummary.partials.totals')

    {{-- the day's refunds: who gave the money back, how much, for which payment, why --}}
    @if ($refunds->isNotEmpty())
        <div class="form-section mt-8">Refunds</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Refund</th><th>Refunded By</th><th>Amount</th><th>Method</th><th>Original Cash In</th><th>Member</th><th>Reason</th></tr></thead>
                <tbody>
                    @foreach ($refunds as $refund)
                        <tr>
                            <td class="whitespace-nowrap"><a href="{{ route('account.payments.show', $refund->id) }}" data-modal class="font-medium">#{{ $refund->payment_no }}</a> <span class="text-xs text-slate-500">{{ $refund->created_at?->format('h:i A') }}</span></td>
                            <td>{{ $refund->creatable?->userable?->displayName() ?? '-' }}</td>
                            <td class="whitespace-nowrap font-bold text-danger">{{ number_format(-$refund->amount, 2) }}</td>
                            <td>{{ $refund->paymentMethod?->name ?? '-' }}</td>
                            <td>
                                @if ($refund->refundOf)
                                    <a href="{{ route('account.payments.show', $refund->refundOf->id) }}" data-modal>#{{ $refund->refundOf->payment_no }}</a>
                                    <span class="text-xs text-slate-500">{{ number_format($refund->refundOf->amount, 2) }}</span>
                                @else
                                    <span class="text-slate-500">{{ $refund->reference ?: '-' }}</span>
                                @endif
                            </td>
                            <td>{{ $refund->member?->name ?? '-' }}</td>
                            <td>{{ $refund->reason ?: ($refund->notes ?: '-') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
