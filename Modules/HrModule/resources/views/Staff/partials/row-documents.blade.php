{{-- Staff list: the file's document warnings ($alert = StaffService::alerts()['staff'][id] or null) --}}
@if ($alert)
    <div class="staff-doc-badges">
        @if ($alert['expired'])
            <span class="badge badge-status-danger">{{ count($alert['expired']) }} expired</span>
        @endif
        @if ($alert['expiring'])
            <span class="badge badge-status-warning">{{ count($alert['expiring']) }} expiring</span>
        @endif
        @if ($alert['missing'])
            <span class="badge badge-status-pending" title="{{ collect($alert['missing'])->pluck('name')->implode(', ') }}">{{ count($alert['missing']) }} missing</span>
        @endif
    </div>
@else
    <span class="text-slate-400">OK</span>
@endif
