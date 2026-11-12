{{-- the Profit & Loss report under the toolbar (PnlReport::data()): totals box, then the table — a column per month / quarter /
     half-year / year (with its change vs the previous column) + Total [+ the comparison period + Change] (Income | Expenses |
     Net profit | Margin | Below the line) --}}
@php
    $compared ??= false;
    $running ??= null;
    $cols = count($months) + 2 + ($compared ? 2 : 0);
    $change = fn ($current, $previous, $up = true) => \Modules\ReportModule\app\Services\ReportPeriod::change($current, $previous, $up);
    $signed = fn ($value) => round((float) $value, 2) < 0 ? 'money-out' : 'money-in';
@endphp
@if ($error)
    <div class="notice notice--warning">{{ $error }}</div>
@else
    <div class="money-box">
        <div class="money-box__item money-box__item--main"><span>Net profit</span><b class="{{ $signed($totals['net']['total']) }}">{{ number_format($totals['net']['total'], 2) }}</b>
            @if ($compared)@include('reportmodule::Report.partials.change', ['change' => $change($totals['net']['total'], $totals['net']['compare'])])@endif</div>
        <div class="money-box__item"><span>Income (net before tax)</span><b class="money-in">{{ number_format($totals['income']['total'], 2) }}</b>
            @if ($compared)@include('reportmodule::Report.partials.change', ['change' => $change($totals['income']['total'], $totals['income']['compare'])])@endif</div>
        <div class="money-box__item"><span>Expenses</span><b class="money-out">{{ number_format($totals['expenses']['total'], 2) }}</b>
            @if ($compared)@include('reportmodule::Report.partials.change', ['change' => $change($totals['expenses']['total'], $totals['expenses']['compare'], false)])@endif</div>
        <div class="money-box__item"><span>Margin</span><b>{{ $totals['margin']['total'] === null ? '-' : $totals['margin']['total'] . '%' }}</b>
            @if ($compared)<span class="text-slate-500 text-xs">was {{ $totals['margin']['compare'] === null ? '-' : $totals['margin']['compare'] . '%' }}</span>@endif</div>
        <div class="money-box__item"><span>{{ \Modules\ReportModule\app\Services\PnlReport::COLUMNS[$columns] ?? 'Days' }} · {{ count($months) }}</span><b>{{ $range }}</b>
            @if ($compared)<span class="text-slate-500 text-xs">vs {{ $cmpRange }}</span>@endif</div>
    </div>

    <div class="table-wrap mt-5">
        <table class="data-table pnl-table">
            <thead>
                <tr>
                    <th></th>
                    @foreach ($months as $key => $label)
                        @if (($running['key'] ?? null) === (string) $key)
                            <th class="text-right" title="{{ $running['prev_range'] ? 'Up to ' . $running['to'] . '; its change = vs ' . $running['prev_range'] . ' (the same days of the previous column)' : 'Up to ' . $running['to'] }}">{{ $label }}</th>
                        @else
                            <th class="text-right">{{ $label }}</th>
                        @endif
                    @endforeach
                    <th class="text-right">Total</th>
                    @if ($compared)
                        <th class="text-right pnl-table__compare">{{ $cmpRange }}</th>
                        <th class="text-right">Change</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                <tr class="pnl-table__section"><td colspan="{{ $cols }}">Income</td></tr>
                @forelse ($income as $row)
                    @include('reportmodule::Report.partials.pnl-row', ['row' => $row, 'class' => 'money-in', 'compared' => $compared, 'running' => $running])
                @empty
                    <tr><td colspan="{{ $cols }}" class="text-slate-500">No income in these months.</td></tr>
                @endforelse
                @include('reportmodule::Report.partials.pnl-row', ['row' => ['label' => 'Total income'] + $totals['income'], 'class' => 'money-in', 'total' => true, 'compared' => $compared, 'running' => $running])

                <tr class="pnl-table__section"><td colspan="{{ $cols }}">Expenses</td></tr>
                @forelse ($expenses as $row)
                    @include('reportmodule::Report.partials.pnl-row', ['row' => $row, 'class' => 'money-out', 'compared' => $compared, 'running' => $running, 'upIsGood' => false])
                @empty
                    <tr><td colspan="{{ $cols }}" class="text-slate-500">No Cash Out in these months.</td></tr>
                @endforelse
                @include('reportmodule::Report.partials.pnl-row', ['row' => ['label' => 'Total expenses'] + $totals['expenses'], 'class' => 'money-out', 'total' => true, 'compared' => $compared, 'running' => $running, 'upIsGood' => false])

                <tr @class(['row-net', 'row-net--negative' => $totals['net']['total'] < 0])>
                    <td>Net profit</td>
                    @php($previous = null)
                    @foreach ($totals['net']['months'] as $key => $value)
                        @php($previous = $previous !== null && ($running['key'] ?? null) === (string) $key && $running['prev_range'] ? ($totals['net']['prev_to_date'] ?? 0) : $previous)
                        <td class="text-right"><span class="{{ $signed($value) }}">{{ number_format($value, 2) }}</span>
                            @if ($previous !== null)<div>@include('reportmodule::Report.partials.change', ['change' => $change($value, $previous), 'short' => true])</div>@endif
                        </td>
                        @php($previous = $value)
                    @endforeach
                    <td class="text-right"><span class="{{ $signed($totals['net']['total']) }}">{{ number_format($totals['net']['total'], 2) }}</span></td>
                    @if ($compared)
                        <td class="text-right"><span class="{{ $signed($totals['net']['compare']) }}">{{ number_format($totals['net']['compare'], 2) }}</span></td>
                        <td class="text-right">@include('reportmodule::Report.partials.change', ['change' => $change($totals['net']['total'], $totals['net']['compare'])])</td>
                    @endif
                </tr>
                <tr class="pnl-table__muted">
                    <td>Margin</td>
                    @foreach ($months as $ym => $label)
                        <td class="text-right">{{ $totals['margin'][$ym] === null ? '-' : $totals['margin'][$ym] . '%' }}</td>
                    @endforeach
                    <td class="text-right">{{ $totals['margin']['total'] === null ? '-' : $totals['margin']['total'] . '%' }}</td>
                    @if ($compared)
                        <td class="text-right">{{ $totals['margin']['compare'] === null ? '-' : $totals['margin']['compare'] . '%' }}</td>
                        <td></td>
                    @endif
                </tr>

                <tr class="pnl-table__section pnl-table__section--below"><td colspan="{{ $cols }}">Below the line · advances (not in the profit)</td></tr>
                @foreach ($below as $row)
                    @include('reportmodule::Report.partials.pnl-row', ['row' => $row, 'class' => '', 'total' => $row['key'] === 'change', 'compared' => $compared, 'running' => $running, 'upIsGood' => false])
                @endforeach
            </tbody>
        </table>
    </div>
@endif
