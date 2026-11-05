{{--
    The staff page's Salary tab (PayrollModule, docs/hr-plan.md §3.3; hr.salary, not for External staff). Large screens: left
    (2/3) = Current salary + Salary History, right (1/3) = Social Insurance; stacked on phones. Data = SalaryService::tabData()
    ($warnings by card: salary / insurance).
--}}
@php($money = fn ($value) => number_format((float) $value, 2))
@php($percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'))
<div class="salary-layout">
    <div class="salary-layout__main">
        <div class="hr-card">
            <div class="hr-card__head">
                <span>Current Salary @if ($current) <span class="hr-card__sub">from {{ $current->effective_from->format('d-m-Y') }}</span> @endif</span>
                <a href="{{ route($area . '.payroll.salaries.create', $current ? [$staff->id, 'from' => $current->id] : $staff->id) }}" data-modal class="btn btn-primary btn-sm">
                    <i data-lucide="plus"></i> {{ $current ? 'New Salary' : 'Add Salary' }}
                </a>
            </div>
            @foreach ($warnings['salary'] as $warning)
                <div class="notice notice--warning mb-3">{{ $warning }}</div>
            @endforeach
            @if ($current)
                <div class="stat-boxes">
                    <div class="stat-box"><span>Basic</span><b>{{ $money($current->basic) }}</b></div>
                    <div class="stat-box"><span>Allowances</span><b>{{ $money($current->allowances_total) }}</b></div>
                    <div class="stat-box stat-box--main"><span>Total</span><b>{{ $money($current->total) }}</b></div>
                    <div class="stat-box"><span>Insurable</span><b>{{ $money($current->insurable) }}</b></div>
                </div>
                @if ($current->items->isNotEmpty())
                    <div class="salary-items">
                        @foreach ($current->items as $item)
                            <span>{{ $item->type?->name ?? '-' }}: <b>{{ $money($item->amount) }}</b>@if ($item->type?->is_insurable) <span class="badge badge-status-info">Insurable</span>@endif</span>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="text-slate-500">No salary yet.</div>
            @endif
            @if ($upcoming)
                <div class="notice notice--info mt-3">Next salary from {{ $upcoming->effective_from->format('d-m-Y') }}: {{ $money($upcoming->total) }}.</div>
            @endif
        </div>

        @if ($salaries->isNotEmpty())
            <div class="hr-card">
                <div class="hr-card__head"><span>Salary History</span></div>
                <div class="table-wrap">
                    <table class="data-table salary-history">
                        <thead>
                            <tr>
                                <th>From</th>
                                <th class="text-right">Basic</th>
                                <th>Allowances</th>
                                <th class="text-right">Total</th>
                                <th>Reason</th>
                                <th>By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($salaries as $salary)
                                <tr @class(['row-muted' => $current && $salary->id !== $current->id && $salary->effective_from->lt($current->effective_from)])>
                                    <td class="whitespace-nowrap">
                                        {{ $salary->effective_from->format('d-m-Y') }}
                                        @if ($current && $salary->id === $current->id) <span class="badge badge-status-success">Current</span>
                                        @elseif ($salary->effective_from->isFuture()) <span class="badge badge-status-info">Upcoming</span> @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">{{ $money($salary->basic) }}</td>
                                    <td>{{ $salary->itemsText() ?: '-' }}</td>
                                    <td class="text-right whitespace-nowrap font-medium">{{ $money($salary->total) }}</td>
                                    <td>{{ $salary->reason ?: ($loop->last ? 'Opening salary' : '-') }}</td>
                                    <td class="whitespace-nowrap text-slate-500">{{ $salary->creatable?->userable?->displayName() ?? '-' }}</td>
                                    @php($older = $salaries->get($loop->index + 1))
                                    {{-- deleting the OLDEST row leaves the months before the next one without a salary (payroll pays 0) --}}
                                    @php($gap = $older ? null : ($loop->index > 0 ? 'From ' . $salary->effective_from->format('d-m-Y') . ' to ' . $salaries->get($loop->index - 1)->effective_from->copy()->subDay()->format('d-m-Y') . ' there will be NO salary (payroll pays 0 for those months).' : 'This person will have NO salary at all.'))
                                    <td>@include('payrollmodule::Salary.partials.row-actions', ['gap' => $gap])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <div class="salary-layout__side">
        <div class="hr-card">
            <div class="hr-card__head">
                <span>Social Insurance</span>
                <a href="{{ route($area . '.payroll.insurance.edit', $staff->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>
            </div>
            @foreach ($warnings['insurance'] as $warning)
                <div class="notice notice--warning mb-3">{{ $warning }}</div>
            @endforeach
            <dl class="info-rows">
                <div><dt>Insured</dt><dd>{{ $insurance->is_insured ? 'Yes' : 'No' }}</dd></div>
                <div><dt>Insurance No.</dt><dd>{{ $insurance->insurance_no ?: '-' }}</dd></div>
                <div><dt>Start</dt><dd>{{ $insurance->start_date?->format('d-m-Y') ?? '-' }}</dd></div>
                <div><dt>End</dt><dd>{{ $insurance->end_date?->format('d-m-Y') ?? '-' }}</dd></div>
                <div><dt>Insured Wage</dt><dd>{{ $insurance->insured_wage !== null ? $money($insurance->insured_wage) : '-' }}</dd></div>
                <div><dt>Employee Share @if ($settings->insurance_employee_percent !== null) ({{ $percent($settings->insurance_employee_percent) }}%) @endif</dt><dd>{{ $shares ? $money($shares[0]) : '-' }}</dd></div>
                <div><dt>Company Share @if ($settings->insurance_company_percent !== null) ({{ $percent($settings->insurance_company_percent) }}%) @endif</dt><dd>{{ $shares ? $money($shares[1]) : '-' }}</dd></div>
            </dl>
            @if ($insurance->notes)
                <div class="mt-3 text-slate-500">{{ $insurance->notes }}</div>
            @endif
        </div>
    </div>
</div>
