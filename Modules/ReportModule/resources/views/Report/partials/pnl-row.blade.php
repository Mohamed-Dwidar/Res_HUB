{{-- one Profit & Loss line: label | a cell per column | Total — each cell = Report.partials.pnl-cell (3 lines with a comparison:
     amount, the compared amount + its label, the change). Columns: vs $colCompare (PnlReport's data['col_compare'] from the page's Compare;
     the running column on the same days); Total: vs the compared range ($cmpRange, row['compare']). $class = the amounts' colour ('signed' = green / red by sign), $total = a bold line,
     $trClass = the row's classes, $upIsGood = false for expenses (a rise is red), $percent = the margin line --}}
@php
    $upIsGood ??= true;
    $colCompare ??= null;
    $cmpRange ??= null;
    $percent ??= false;
    $classOf = fn ($value) => $class === 'signed' ? (round((float) $value, 2) < 0 ? 'money-out' : 'money-in') : $class;
    [$totalCmp, $totalLabel] = $cmpRange ? [$row['compare'] ?? ($percent ? null : 0), $cmpRange] : ($colCompare ? [$row['cmp_total'] ?? ($percent ? null : 0), $colCompare['range']] : [null, null]);
@endphp
<tr @class([$trClass ?? null, 'pnl-table__total' => !empty($total)])>
    <td>{{ $row['label'] }}</td>
    @foreach ($row['months'] as $key => $value)
        @include('reportmodule::Report.partials.pnl-cell', ['value' => $value, 'class' => $classOf($value), 'upIsGood' => $upIsGood, 'percent' => $percent,
            'cmp' => $colCompare ? ($row['cmp_months'][$key] ?? ($percent ? null : 0)) : null, 'label' => $colCompare ? $colCompare['labels'][(string) $key] : null])
    @endforeach
    @include('reportmodule::Report.partials.pnl-cell', ['value' => $row['total'], 'class' => $classOf($row['total']), 'upIsGood' => $upIsGood, 'percent' => $percent,
        'cmp' => $totalCmp, 'label' => $totalLabel])
</tr>
