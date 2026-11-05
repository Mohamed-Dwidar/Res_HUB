{{-- A payroll line (popup): what it is made of (earnings, deductions, the company insurance share), warnings, payments (+ Undo) --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $line->name }} · {{ $run->period->format('F Y') }}
@endsection

@section('content')
    @php($money = fn ($value) => number_format((float) $value, 2))
    @if ($line->warnings)
        <div class="notice notice--warning mb-4">
            @foreach ($line->warnings as $warning)<div>{{ $warning }}</div>@endforeach
        </div>
    @endif
    <div class="text-sm text-slate-500 mb-3">#{{ $line->snapshot['employee_no'] ?? '-' }} · {{ $line->snapshot['position'] ?? 'No job title' }}
        · {{ rtrim(rtrim(number_format($line->days_in_service, 2), '0'), '.') }} of {{ $line->month_days }} days</div>
    <div class="table-wrap">
        <table class="data-table salary-history">
            <tbody>
                @foreach ($line->items->where('kind', 'earning') as $item)
                    <tr><td>{{ $item->label }}@if ($item->quantity) <span class="text-xs text-slate-500">· {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} days</span>@endif</td><td class="text-right text-success">+{{ $money($item->amount) }}</td></tr>
                @endforeach
                @foreach ($line->items->where('kind', 'deduction') as $item)
                    <tr><td>{{ $item->label }}@if ($item->quantity) <span class="text-xs text-slate-500">· {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} days</span>@endif</td><td class="text-right text-danger">−{{ $money($item->amount) }}</td></tr>
                @endforeach
                <tr class="row-total"><td>Net</td><td class="text-right font-bold">{{ $money($line->net) }}</td></tr>
                @foreach ($line->items->where('kind', 'info') as $item)
                    <tr><td class="text-slate-500">{{ $item->label }}</td><td class="text-right text-slate-500">{{ $money($item->amount) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($line->notes)
        <div class="mt-3 text-sm">{{ $line->notes }}</div>
    @endif

    @if ($line->payments->isNotEmpty())
        <div class="advance-details__label">Payments · paid {{ $money($line->paid_amount) }} · remaining {{ $money($line->remaining) }}</div>
        <div class="table-wrap">
            <table class="data-table salary-history">
                <tbody>
                    @foreach ($line->payments as $payment)
                        <tr @class(['row-muted' => $payment->cancelled_at])>
                            <td>{{ $payment->paid_on->format('d-m-Y') }}</td>
                            <td>{{ $payment->paymentMethod?->name ?? '-' }}</td>
                            <td class="text-right">{{ $money($payment->amount) }}</td>
                            <td class="text-slate-500">{{ $payment->creatable?->userable?->displayName() ?? '-' }}@if ($payment->cancelled_at) · undone: {{ $payment->cancel_reason }}@endif</td>
                            <td>
                                @if (!$payment->cancelled_at && Gate::allows('payroll.pay'))
                                    <a href="{{ route($area . '.payroll.runs.undo.form', [$run->id, $payment->id]) }}" data-modal class="text-danger text-sm">Undo</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="form-actions">
        <a href="{{ route($area . '.payroll.runs.payslip', [$run->id, $line->id]) }}" target="_blank" class="btn btn-secondary"><i data-lucide="file-text"></i> Payslip (EN)</a>
        <a href="{{ route($area . '.payroll.runs.payslip', [$run->id, $line->id, 'lang' => 'ar']) }}" target="_blank" class="btn btn-secondary"><i data-lucide="file-text"></i> Payslip (AR)</a>
        @if (!$run->isLocked())
            <a href="{{ route($area . '.payroll.runs.line.edit', [$run->id, $line->id]) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Tax &amp; Notes</a>
        @endif
    </div>
@endsection
