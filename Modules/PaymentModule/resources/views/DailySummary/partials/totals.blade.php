{{--
    A day's totals (DailySummaryService::summary(), the shift totals shape): per method Cash In - Refunds - Cash Out = net
    (the shift totals table: Cash In, Refunds, Cash Out, Net to hand over, withholding apart), then the
    same per collector (a totals row + its methods). $summary: ['collectors', 'methods', 'refunds', 'expenses', 'withholding', 'cash_total', 'net_total', 'count']
--}}
@php
    $methods = $summary['methods'] ?? [];
    $methodName = fn ($id) => $methods[$id]['name'] ?? 'Method #' . $id;
    // the money colors of Shift/partials/totals (custom.css .money-*)
    $inClass = fn ($value) => (float) $value ? 'money-in' : 'money-zero';
    $outClass = fn ($value) => (float) $value ? 'money-out' : 'money-zero';
    $netClass = fn ($value) => 'money-net' . ((float) $value < 0 ? ' money-net--negative' : '');
@endphp

@include('paymentmodule::Shift.partials.totals')

@if (!empty($summary['collectors']))
    {{-- By Collector (redesigned 2026-10-26): one bold row per collector with their totals, then their methods indented and
         lighter (always, also for one method: user 2026-10-27); an empty closing = the tag "No cash in / out"; amounts right aligned --}}
    <div class="form-section mt-8">By Collector</div>
    <div class="table-wrap">
        <table class="data-table collector-table">
            <thead><tr><th>Collected By</th><th class="num">Cash In</th><th class="num">Refunds</th><th class="num">Cash Out</th><th class="num">Net</th></tr></thead>
            <tbody>
                @foreach ($summary['collectors'] as $collector)
                    @php
                        $lines = collect($collector['methods']);
                        $totalIn = $lines->sum('payments');
                        $totalRefunds = $lines->sum('refunds');
                        $totalOut = $lines->sum('expenses');
                        $totalNet = (float) ($collector['net'] ?? $lines->sum('net'));
                    @endphp
                    <tr class="collector-table__person">
                        <td>{{ $collector['name'] }}@if (!empty($collector['empty'])) <span class="collector-table__method-tag">No cash in / out · shift closed</span>@endif</td>
                        <td class="num {{ $inClass($totalIn) }}">{{ number_format($totalIn, 2) }}</td>
                        <td class="num {{ $outClass($totalRefunds) }}">{{ number_format(-$totalRefunds, 2) }}</td>
                        <td class="num {{ $outClass($totalOut) }}">{{ number_format(-$totalOut, 2) }}</td>
                        <td class="num {{ $netClass($totalNet) }}">{{ number_format($totalNet, 2) }}</td>
                    </tr>
                        @foreach ($lines as $methodId => $line)
                            <tr class="collector-table__method">
                                <td>{{ $methodName($methodId) }}</td>
                                <td class="num {{ $inClass($line['payments']) }}">{{ number_format($line['payments'], 2) }}</td>
                                <td class="num {{ $outClass($line['refunds']) }}">{{ number_format(-$line['refunds'], 2) }}</td>
                                <td class="num {{ $outClass($line['expenses']) }}">{{ number_format(-$line['expenses'], 2) }}</td>
                                <td class="num {{ (float) $line['net'] < 0 ? 'money-out' : '' }}">{{ number_format($line['net'], 2) }}</td>
                            </tr>
                        @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
@endif
