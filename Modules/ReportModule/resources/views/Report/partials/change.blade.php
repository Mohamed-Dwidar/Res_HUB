{{-- the change from the comparison period ($change = ReportPeriod::change(): amount, percent, good): green good / red bad, grey none;
     $short = the % only (table cells), $pill = a filled badge (P&L cells) --}}
@php($good = $change['good'])
<span @class(['change-badge', 'change-badge--good' => $good === true, 'change-badge--bad' => $good === false, 'change-badge--pill' => !empty($pill)])
    title="{{ ($change['amount'] > 0 ? '+' : '') . number_format($change['amount'], 2) }}">
    @if ($change['amount'] == 0)
        =
    @else
        {{ $change['amount'] > 0 ? '▲' : '▼' }}
        @if (empty($short)){{ number_format(abs($change['amount']), 2) }}@endif
        {{ $change['percent'] === null ? (empty($short) ? '' : 'new') : (empty($short) ? '(' . abs($change['percent']) . '%)' : abs($change['percent']) . '%') }}
    @endif
</span>
