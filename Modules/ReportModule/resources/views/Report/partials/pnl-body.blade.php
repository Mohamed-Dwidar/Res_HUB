{{-- the Profit & Loss report under the toolbar (PnlReport::data()): totals box, then the table — a column per month / quarter /
     half-year / year + Total; with a comparison each cell = 3 lines (Report.partials.pnl-cell): columns vs the page's Compare
     (col_compare), Total vs the compared range (Income | Expenses | Net profit | Margin | Below the line) --}}
@php
    $compared ??= false;
    $running ??= null;
    $col_compare ??= null;
    $cols = count($months) + 2;
    // every line: columns vs col_compare; Total vs the picked comparison (else vs the columns' compared range)
    $line = ['colCompare' => $col_compare, 'cmpRange' => $compared ? $cmpRange : null];
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

    {{-- the table scrolls sideways inside its card, the first column stays (custom.css .pnl-table) --}}
    <div class="box mt-5">
        <div class="table-wrap">
            <table class="data-table pnl-table">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($months as $key => $label)
                            <th class="text-right" @if (($running['key'] ?? null) === (string) $key) title="Up to {{ $running['to'] }}" @endif>{{ $label }}</th>
                        @endforeach
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="pnl-table__section"><td colspan="{{ $cols }}">Income</td></tr>
                    @forelse ($income as $row)
                        @include('reportmodule::Report.partials.pnl-row', $line + ['row' => $row, 'class' => 'money-in'])
                    @empty
                        <tr><td colspan="{{ $cols }}" class="text-slate-500">No income in these months.</td></tr>
                    @endforelse
                    @include('reportmodule::Report.partials.pnl-row', $line + ['row' => ['label' => 'Total income'] + $totals['income'], 'class' => 'money-in', 'total' => true])

                    <tr class="pnl-table__section"><td colspan="{{ $cols }}">Expenses</td></tr>
                    @forelse ($expenses as $row)
                        @include('reportmodule::Report.partials.pnl-row', $line + ['row' => $row, 'class' => 'money-out', 'upIsGood' => false])
                    @empty
                        <tr><td colspan="{{ $cols }}" class="text-slate-500">No Cash Out in these months.</td></tr>
                    @endforelse
                    @include('reportmodule::Report.partials.pnl-row', $line + ['row' => ['label' => 'Total expenses'] + $totals['expenses'], 'class' => 'money-out', 'total' => true, 'upIsGood' => false])

                    @include('reportmodule::Report.partials.pnl-row', $line + ['row' => $totals['net'], 'class' => 'signed',
                        'trClass' => $totals['net']['total'] < 0 ? 'row-net row-net--negative' : 'row-net'])
                    @include('reportmodule::Report.partials.pnl-row', $line + ['row' => ['label' => 'Margin', 'months' => collect($months)->map(fn ($l, $ym) => $totals['margin'][$ym])->all(),
                        'total' => $totals['margin']['total'], 'compare' => $totals['margin']['compare'] ?? null]
                        + ($totals['margin_cmp'] ?? []), 'class' => '', 'percent' => true, 'trClass' => 'pnl-table__muted'])

                    <tr class="pnl-table__section pnl-table__section--below"><td colspan="{{ $cols }}">Below the line · advances (not in the profit)</td></tr>
                    @foreach ($below as $row)
                        @include('reportmodule::Report.partials.pnl-row', $line + ['row' => $row, 'class' => '', 'total' => $row['key'] === 'change', 'upIsGood' => false])
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
