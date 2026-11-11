{{-- one Profit & Loss line: label | a cell per month | Total ($class = the amounts' colour, $total = a bold totals line) --}}
<tr @class(['pnl-table__total' => !empty($total)])>
    <td>{{ $row['label'] }}</td>
    @foreach ($row['months'] as $value)
        <td class="text-right">@include('reportmodule::Report.partials.money', ['value' => $value, 'class' => $class])</td>
    @endforeach
    <td class="text-right pnl-table__sum">@include('reportmodule::Report.partials.money', ['value' => $row['total'], 'class' => $class])</td>
</tr>
