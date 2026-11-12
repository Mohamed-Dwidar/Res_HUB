{{-- the Financial Dashboard under the toolbar (DashboardReport::data()): KPI cards, then the charts; the charts' data in
     [data-dash-data] (custom.js "Financial Dashboard"); $links = each report's url on the same period (null = the login can't open it) --}}
@if ($error)
    <div class="notice notice--warning">{{ $error }}</div>
@else
    @php
        $cards = [
            ['kpi' => $kpis['income'], 'label' => 'Income', 'class' => 'money-in', 'link' => $links['income'], 'note' => 'net before tax'],
            ['kpi' => $kpis['expenses'], 'label' => 'Expenses', 'class' => 'money-out', 'link' => $links['expenses'], 'note' => 'Cash Out, no advances'],
            ['kpi' => $kpis['net'], 'label' => 'Net profit', 'class' => $kpis['net']['value'] < 0 ? 'money-out' : 'money-in', 'link' => $links['pnl'],
                'note' => 'margin ' . ($kpis['net']['margin'] === null ? '-' : $kpis['net']['margin'] . '%')
                    . ($cmpRange ? ' (was ' . ($kpis['net']['cmp_margin'] === null ? '-' : $kpis['net']['cmp_margin'] . '%') . ')' : '')],
            ['kpi' => $kpis['owed'], 'label' => 'Owed by members', 'class' => 'money-out', 'link' => $links['outstanding'],
                'note' => 'on ' . $kpis['owed']['date'] . ($cmpRange ? ' vs ' . $kpis['owed']['cmp_date'] : '')],
        ];
    @endphp
    <div class="dash-period">
        <b>{{ $period['label'] }}</b> <span class="text-slate-500">· {{ $range }}</span>
        @if ($cmpRange)
            <span class="text-slate-500">compared with {{ $cmpRange }}</span>
        @endif
    </div>

    <div class="dash-cards mt-3">
        @foreach ($cards as $item)
            <{{ $item['link'] ? 'a' : 'div' }} @if ($item['link']) href="{{ $item['link'] }}" @endif class="box dash-card">
                <span class="dash-card__label">{{ $item['label'] }}</span>
                <b class="dash-card__value {{ $item['class'] }}">{{ number_format($item['kpi']['value'], 2) }}</b>
                @if ($item['kpi']['change'])
                    <span class="dash-card__change">
                        @include('reportmodule::Report.partials.change', ['change' => $item['kpi']['change']])
                        <span class="text-slate-500">vs {{ number_format($item['kpi']['compare'], 2) }}</span>
                    </span>
                @endif
                <span class="dash-card__note">{{ $item['note'] }}</span>
            </{{ $item['link'] ? 'a' : 'div' }}>
        @endforeach
    </div>

    @php
        $data = $charts;
        $charts = [
            ['key' => 'trend', 'title' => 'Income · Expenses · Net profit', 'link' => $links['pnl'], 'wide' => true,
                'empty' => !collect($data['trend']['income'])->merge($data['trend']['expenses'])->filter()->count()],
            ['key' => 'income', 'title' => 'Income by subscription type', 'link' => $links['income_type'], 'empty' => !$data['income']],
            ['key' => 'expenses', 'title' => 'Expenses by category', 'link' => $links['expenses'], 'empty' => !$data['expenses']],
            ['key' => 'members', 'title' => 'Top 10 members by paid', 'link' => $links['members'], 'empty' => !$data['members']],
            ['key' => 'occupancy', 'title' => 'Occupancy % per space', 'link' => $links['occupancy'],
                'empty' => !empty($data['occupancy']['error']) || !count($data['occupancy']['labels'] ?? []),
                'message' => $data['occupancy']['error'] ?? null],
        ];
    @endphp
    <div class="dash-charts mt-5">
        @foreach ($charts as $chart)
            <div @class(['box dash-chart', 'dash-chart--wide' => !empty($chart['wide'])])>
                <div class="dash-chart__head">
                    <b>{{ $chart['title'] }}</b>
                    @if ($chart['link'])
                        <a href="{{ $chart['link'] }}" class="dash-chart__link">Open report →</a>
                    @endif
                </div>
                @if ($chart['empty'])
                    <div class="dash-chart__empty">{{ $chart['message'] ?? 'Nothing in this period.' }}</div>
                @else
                    <div @class(['dash-chart__canvas', 'dash-chart__canvas--tall' => $chart['key'] === 'members' || $chart['key'] === 'occupancy'])>
                        <canvas data-dash-chart="{{ $chart['key'] }}" role="img" aria-label="{{ $chart['title'] }}"></canvas>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    <script type="application/json" data-dash-data>@json($data + ['cmpRange' => $cmpRange, 'range' => $range])</script>
@endif
