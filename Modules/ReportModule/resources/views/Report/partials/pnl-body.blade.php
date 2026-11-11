{{-- the Profit & Loss report under the toolbar (PnlReport::data()): totals box, then the month table (Income | Expenses | Net profit |
     Margin | Below the line) --}}
@php
    $cols = count($months) + 2;
    $signed = fn ($value) => round((float) $value, 2) < 0 ? 'money-out' : 'money-in';
@endphp
@if ($error)
    <div class="notice notice--warning">{{ $error }}</div>
@else
    <div class="money-box">
        <div class="money-box__item money-box__item--main"><span>Net profit</span><b class="{{ $signed($totals['net']['total']) }}">{{ number_format($totals['net']['total'], 2) }}</b></div>
        <div class="money-box__item"><span>Income (net before tax)</span><b class="money-in">{{ number_format($totals['income']['total'], 2) }}</b></div>
        <div class="money-box__item"><span>Expenses</span><b class="money-out">{{ number_format($totals['expenses']['total'], 2) }}</b></div>
        <div class="money-box__item"><span>Margin</span><b>{{ $totals['margin']['total'] === null ? '-' : $totals['margin']['total'] . '%' }}</b></div>
        <div class="money-box__item"><span>Months · {{ count($months) }}</span><b>{{ $range }}</b></div>
    </div>

    <div class="table-wrap mt-5">
        <table class="data-table pnl-table">
            <thead>
                <tr>
                    <th></th>
                    @foreach ($months as $label)
                        <th class="text-right">{{ $label }}</th>
                    @endforeach
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr class="pnl-table__section"><td colspan="{{ $cols }}">Income</td></tr>
                @forelse ($income as $row)
                    @include('reportmodule::Report.partials.pnl-row', ['row' => $row, 'class' => 'money-in'])
                @empty
                    <tr><td colspan="{{ $cols }}" class="text-slate-500">No income in these months.</td></tr>
                @endforelse
                @include('reportmodule::Report.partials.pnl-row', ['row' => $totals['income'] + ['label' => 'Total income'], 'class' => 'money-in', 'total' => true])

                <tr class="pnl-table__section"><td colspan="{{ $cols }}">Expenses</td></tr>
                @forelse ($expenses as $row)
                    @include('reportmodule::Report.partials.pnl-row', ['row' => $row, 'class' => 'money-out'])
                @empty
                    <tr><td colspan="{{ $cols }}" class="text-slate-500">No Cash Out in these months.</td></tr>
                @endforelse
                @include('reportmodule::Report.partials.pnl-row', ['row' => $totals['expenses'] + ['label' => 'Total expenses'], 'class' => 'money-out', 'total' => true])

                <tr @class(['row-net', 'row-net--negative' => $totals['net']['total'] < 0])>
                    <td>Net profit</td>
                    @foreach ($totals['net']['months'] as $value)
                        <td class="text-right"><span class="{{ $signed($value) }}">{{ number_format($value, 2) }}</span></td>
                    @endforeach
                    <td class="text-right"><span class="{{ $signed($totals['net']['total']) }}">{{ number_format($totals['net']['total'], 2) }}</span></td>
                </tr>
                <tr class="pnl-table__muted">
                    <td>Margin</td>
                    @foreach ($months as $ym => $label)
                        <td class="text-right">{{ $totals['margin'][$ym] === null ? '-' : $totals['margin'][$ym] . '%' }}</td>
                    @endforeach
                    <td class="text-right">{{ $totals['margin']['total'] === null ? '-' : $totals['margin']['total'] . '%' }}</td>
                </tr>

                <tr class="pnl-table__section pnl-table__section--below"><td colspan="{{ $cols }}">Below the line · advances (not in the profit)</td></tr>
                @foreach ($below as $row)
                    @include('reportmodule::Report.partials.pnl-row', ['row' => $row, 'class' => '', 'total' => $row['key'] === 'change'])
                @endforeach
            </tbody>
        </table>
    </div>
@endif
