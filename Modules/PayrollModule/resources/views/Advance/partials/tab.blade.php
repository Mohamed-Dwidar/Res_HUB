{{--
    The staff page's "Advances & Adjustments" tab (PayrollModule, docs/hr-plan.md §3.5; hr.salary, not for External staff).
    Left: open advances = one compact row each (date, amount, remaining, monthly installment, next deduction, status) + a
    "Details" toggle (planned schedule, payment / Cash Out, moves); settled / cancelled under "Closed advances". Right: the
    bonuses / deductions by month. Nothing is deducted / paid here: payroll (H5) applies them. $advances, $adjustments, $advanceService.
--}}
@php($money = fn ($value) => number_format((float) $value, 2))
@php($isOwner = Auth::user()->userable instanceof \Modules\AccountModule\app\Models\Account)
@php([$openAdvances, $closedAdvances] = $advances->partition(fn ($advance) => $advance->status === 'open'))
@php($days = fn ($value) => rtrim(rtrim(number_format($value, 2), '0'), '.') . ' ' . ($value == 1 ? 'day' : 'days'))
@php($part = fn ($row, $sign) => collect([$row['amount'] ? $sign . $money($row['amount']) : null, $row['days'] ? $sign . $days($row['days']) : null])->filter()->implode(' ') ?: 'none')
{{-- the next payroll month (this month): what payroll will take / add; warnings = months above the settings' % of the salary --}}
<div class="advance-summary">
    <b>{{ $summary['month']->format('M Y') }}</b> —
    installments <b>{{ $money($summary['installments']) }}</b> ·
    bonuses <b class="text-success">{{ $part($summary['bonus'], '+') }}</b> ·
    deductions <b class="text-danger">{{ $part($summary['deduction'], '−') }}</b>
</div>
@if ($summary['warnings'])
    <div class="notice notice--warning mb-4">
        @foreach ($summary['warnings'] as $warning)
            <div>{{ $warning }}</div>
        @endforeach
    </div>
@endif
<div class="salary-layout salary-layout--7-5">
    <div class="salary-layout__main">
        <div class="hr-card">
            <div class="hr-card__head">
                <span>Advances</span>
                <a href="{{ route($area . '.payroll.advances.create', $staff->id) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Give Advance</a>
            </div>
            @forelse ($openAdvances as $advance)
                @include('payrollmodule::Advance.partials.advance-row')
            @empty
                <div class="text-slate-500">No open advances.</div>
            @endforelse

            @if ($closedAdvances->isNotEmpty())
                <details class="closed-advances">
                    <summary>Closed advances ({{ $closedAdvances->count() }})</summary>
                    @foreach ($closedAdvances as $advance)
                        @include('payrollmodule::Advance.partials.advance-row')
                    @endforeach
                </details>
            @endif
        </div>
    </div>

    <div class="salary-layout__side">
        <div class="hr-card">
            <div class="hr-card__head">
                <span>Bonuses &amp; Deductions</span>
                <a href="{{ route($area . '.payroll.adjustments.create', $staff->id) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add</a>
            </div>
            <div class="text-xs text-slate-500 mb-3">Applied by the payroll of their month.</div>
            @forelse ($adjustments as $adjustment)
                {{-- one line: badge · type · month · amount (right) · menu; reason / by / cancel reason in the tooltip --}}
                <div @class(['adjustment-row', 'adjustment-row--muted' => $adjustment->trashed()])
                    title="{{ $adjustment->reason }} · by {{ $adjustment->creatable?->userable?->displayName() ?? '-' }} · {{ $adjustment->created_at?->format('d-m-Y') }}{{ $adjustment->trashed() ? ' · Cancelled: ' . $adjustment->delete_reason : '' }}">
                    <span class="badge {{ $adjustment->isDeduction() ? 'badge-status-danger' : 'badge-status-success' }}">{{ $adjustment->type?->kind_label }}</span>
                    <span class="adjustment-row__type">{{ $adjustment->type?->name ?? '-' }}</span>
                    <span class="text-slate-500 whitespace-nowrap">{{ $adjustment->period->format('M Y') }}</span>
                    <b @class(['adjustment-row__amount', 'text-danger' => $adjustment->isDeduction(), 'text-success' => !$adjustment->isDeduction()])>{{ $adjustment->isDeduction() ? '−' : '+' }}{{ $adjustment->valueText() }}</b>
                    <span class="adjustment-row__menu">
                        @if (!$adjustment->trashed() && !$adjustment->payroll_line_id)
                            @include('layoutmodule::partials.row-menu', ['links' => [
                                ['label' => 'Cancel', 'icon' => 'x-circle', 'url' => route($area . '.payroll.adjustments.cancel.form', [$staff->id, $adjustment->id]), 'modal' => true],
                            ]])
                        @elseif ($adjustment->trashed())
                            <span class="text-xs text-slate-400">Cancelled</span>
                        @endif
                    </span>
                </div>
            @empty
                <div class="text-slate-500">None.</div>
            @endforelse
        </div>
    </div>
</div>
