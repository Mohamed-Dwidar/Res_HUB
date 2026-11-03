{{-- the newest Access Log lines of one login (the employee view); $logs --}}
@if ($logs->isNotEmpty())
    <div class="table-wrap mt-3">
        <table class="data-table">
            <thead>
                <tr><th>When</th><th>Event</th><th>Details</th><th>IP / Browser</th></tr>
            </thead>
            <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap">{{ $log->created_at?->format('d-m-Y h:i:s A') }}</td>
                        <td>@include('employeemodule::Account.AccessLog.partials.event')</td>
                        <td>@include('employeemodule::Account.AccessLog.partials.details')</td>
                        <td>@include('employeemodule::Account.AccessLog.partials.client')</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <div class="mt-3 text-slate-500">Nothing logged yet.</div>
@endif
