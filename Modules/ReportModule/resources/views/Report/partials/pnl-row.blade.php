{{-- one Profit & Loss line: label | a cell per column (+ its change vs the previous column; the running column: vs the same days
     of the previous one, $running = PnlReport's data['running']) | Total [| Compare | Change]
     ($class = the amounts' colour, $total = a bold totals line, $upIsGood = false for expenses: a rise is red) --}}
@php
    $upIsGood ??= true;
    $running ??= null;
    $previous = null;
@endphp
<tr @class(['pnl-table__total' => !empty($total)])>
    <td>{{ $row['label'] }}</td>
    @foreach ($row['months'] as $key => $value)
        {{-- the running column ("to date") is compared with the same days of the previous column --}}
        @php($previous = $previous !== null && ($running['key'] ?? null) === (string) $key && ($running['prev_range'] ?? null) ? ($row['prev_to_date'] ?? 0) : $previous)
        <td class="text-right">
            @include('reportmodule::Report.partials.money', ['value' => $value, 'class' => $class])
            @if ($previous !== null && ($value != 0 || $previous != 0))
                <div>@include('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($value, $previous, $upIsGood), 'short' => true])</div>
            @endif
        </td>
        @php($previous = $value)
    @endforeach
    <td class="text-right pnl-table__sum">@include('reportmodule::Report.partials.money', ['value' => $row['total'], 'class' => $class])</td>
    @if (!empty($compared))
        <td class="text-right pnl-table__compare">@include('reportmodule::Report.partials.money', ['value' => $row['compare'] ?? 0, 'class' => $class])</td>
        <td class="text-right">@include('reportmodule::Report.partials.change', ['change' => \Modules\ReportModule\app\Services\ReportPeriod::change($row['total'], $row['compare'] ?? 0, $upIsGood)])</td>
    @endif
</tr>
