{{--
    HR › Payroll (docs/hr-plan.md §6, H5): ONE month (‹ November 2026 ›). Draft: Calculate / Recalculate (hr.salary; reads only),
    each line's tax + notes; Lock (payroll.approve); locked: Pay / Pay all (payroll.pay; from go-live), Reopen. One row per person,
    totals at the bottom. $month, $run (null = not calculated), $lines, $people, $canPay.
--}}
@extends(Auth::user()->userable->layout())

@section('title')
    Payroll · {{ $month->format('F Y') }}
@endsection

@section('content')
    @php($money = fn ($value) => number_format((float) $value, 2))
    @php($locked = $run?->isLocked())
    <div class="payroll-head">
        <span class="leave-year">
            <a href="{{ route($area . '.payroll.runs.index', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}" class="btn btn-secondary btn-sm"><i data-lucide="chevron-left"></i></a>
            <b>{{ $month->format('F Y') }}</b>
            <a href="{{ route($area . '.payroll.runs.index', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}" class="btn btn-secondary btn-sm"><i data-lucide="chevron-right"></i></a>
            @if (!$run)
                <span class="badge badge-status-slate">Not calculated</span>
            @elseif ($locked)
                <span class="badge badge-status-success" title="{{ $run->locked_at?->format('d-m-Y h:i A') }}">Locked</span>
            @else
                <span class="badge badge-status-warning">Draft</span>
                <span class="text-xs text-slate-500">calculated {{ $run->calculated_at?->format('d-m-Y h:i A') }}</span>
            @endif
        </span>
        <span class="payroll-head__actions">
            @if (!$locked)
                <form method="POST" action="{{ route($area . '.payroll.runs.calculate') }}" data-ajax>
                    @csrf
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                    <button type="submit" class="btn btn-secondary btn-sm"><i data-lucide="calculator"></i> {{ $run ? 'Recalculate' : 'Calculate' }}</button>
                </form>
                @if ($run)
                    @can('payroll.approve')
                        <a href="{{ route($area . '.payroll.runs.lock.form', $run->id) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="lock"></i> Lock</a>
                    @endcan
                @endif
            @else
                @can('payroll.pay')
                    <a href="{{ route($area . '.payroll.runs.pay-all.form', $run->id) }}" data-modal class="btn btn-pay btn-sm"><i data-lucide="banknote"></i> Pay All</a>
                @endcan
                @can('payroll.approve')
                    <a href="{{ route($area . '.payroll.runs.reopen.form', $run->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="unlock"></i> Reopen</a>
                @endcan
            @endif
        </span>
    </div>

    @unless ($canPay)
        <div class="notice notice--info mb-4">Paying from payroll is not switched on yet: the "Salaries" Cash Out category becomes the system category right before paying the first month (go-live step).</div>
    @endunless

    @if (!$run)
        <div class="text-slate-500">{{ $people }} {{ $people == 1 ? 'person' : 'people' }} on payroll in {{ $month->format('F Y') }}. Press Calculate to see the salaries (nothing is changed).</div>
    @else
        @php($warned = $lines->filter(fn ($line) => $line->warnings)->count())
        @if ($warned)
            <div class="notice notice--warning mb-4">{{ $warned }} {{ $warned == 1 ? 'line has' : 'lines have' }} warnings (<i data-lucide="alert-triangle" class="inline h-4 w-4"></i>): open the line to see them.</div>
        @endif
        <div class="table-wrap">
            <table class="data-table payroll-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="text-right">Days</th>
                        <th class="text-right">Gross</th>
                        <th class="text-right">Bonuses</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Unpaid Leave</th>
                        <th class="text-right">Advance</th>
                        <th class="text-right">Insurance</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Net</th>
                        <th class="text-right">Paid</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>
                                <a href="{{ route($area . '.payroll.runs.line', [$run->id, $line->id]) }}" data-modal class="font-medium">{{ $line->name }}</a>
                                @if ($line->warnings) <i data-lucide="alert-triangle" class="inline h-4 w-4 text-warning" title="{{ implode(' · ', $line->warnings) }}"></i> @endif
                                <div class="text-xs text-slate-500">#{{ $line->snapshot['employee_no'] ?? '-' }} · {{ $line->snapshot['position'] ?? 'No job title' }}</div>
                            </td>
                            <td class="text-right">{{ rtrim(rtrim(number_format($line->days_in_service, 2), '0'), '.') }}</td>
                            <td class="text-right">{{ $money($line->gross) }}</td>
                            <td class="text-right text-success">{{ $line->bonuses ? '+' . $money($line->bonuses) : '-' }}</td>
                            <td class="text-right text-danger">{{ $line->deductions ? '−' . $money($line->deductions) : '-' }}</td>
                            <td class="text-right text-danger">{{ $line->unpaid_leave ? '−' . $money($line->unpaid_leave) : '-' }}</td>
                            <td class="text-right text-danger">{{ $line->advance_installment ? '−' . $money($line->advance_installment) : '-' }}</td>
                            <td class="text-right text-danger">{{ $line->insurance_employee ? '−' . $money($line->insurance_employee) : '-' }}</td>
                            <td class="text-right text-danger">{{ $line->income_tax ? '−' . $money($line->income_tax) : '-' }}</td>
                            <td class="text-right font-bold">{{ $money($line->net) }}</td>
                            <td class="text-right">{{ $line->paid_amount ? $money($line->paid_amount) : '-' }}</td>
                            <td>
                                @include('layoutmodule::partials.row-menu', ['links' => array_merge(
                                    [['label' => 'Details', 'icon' => 'eye', 'url' => route($area . '.payroll.runs.line', [$run->id, $line->id]), 'modal' => true]],
                                    $locked ? [] : [['label' => 'Tax & Notes', 'icon' => 'pencil', 'url' => route($area . '.payroll.runs.line.edit', [$run->id, $line->id]), 'modal' => true]],
                                    $locked && $line->remaining > 0.004 && Gate::allows('payroll.pay') ? [['label' => 'Pay', 'icon' => 'banknote', 'url' => route($area . '.payroll.runs.pay.form', [$run->id, $line->id]), 'modal' => true]] : [],
                                    [
                                        ['label' => 'Payslip (EN)', 'icon' => 'file-text', 'url' => route($area . '.payroll.runs.payslip', [$run->id, $line->id]), 'blank' => true],
                                        ['label' => 'Payslip (AR)', 'icon' => 'file-text', 'url' => route($area . '.payroll.runs.payslip', [$run->id, $line->id, 'lang' => 'ar']), 'blank' => true],
                                    ],
                                )])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="row-total">
                        <td>{{ $run->totals['count'] ?? 0 }} people</td>
                        <td></td>
                        <td class="text-right">{{ $money($run->totals['gross'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['bonuses'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['deductions'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['unpaid_leave'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['advance_installment'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['insurance_employee'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['income_tax'] ?? 0) }}</td>
                        <td class="text-right font-bold">{{ $money($run->totals['net'] ?? 0) }}</td>
                        <td class="text-right">{{ $money($run->totals['paid_amount'] ?? 0) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="mt-2 text-xs text-slate-500">Company insurance share (not deducted): {{ $money($run->totals['insurance_company'] ?? 0) }}.</div>

        @if ($run->history)
            <div class="advance-details__label">History</div>
            <div class="text-sm text-slate-500">
                @foreach (array_reverse($run->history) as $entry)
                    <div>{{ \Carbon\Carbon::parse($entry['at'])->format('d-m-Y h:i A') }} · {{ ucfirst($entry['type']) }} by {{ $entry['by'] ?? '-' }}@if (!empty($entry['reason'])) · {{ $entry['reason'] }}@endif @if (isset($entry['totals']['net'])) · net {{ $money($entry['totals']['net']) }}@endif</div>
                @endforeach
            </div>
        @endif
    @endif
@endsection
