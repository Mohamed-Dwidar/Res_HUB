{{-- The file's history (staff_events, newest first): when, what, who, the changes (secret fields: "changed") --}}
<div class="form-section mt-8">History</div>
@php($canSalary = Gate::allows('hr.salary'))
@if ($events->isEmpty())
    <div class="text-slate-500">No changes yet.</div>
@else
    <div class="table-wrap">
        <table class="data-table staff-history">
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td class="whitespace-nowrap">{{ $event->created_at?->format('d-m-Y h:i A') }}</td>
                        <td class="whitespace-nowrap font-medium">{{ $event->type_label }}</td>
                        <td>
                            @if ($event->isPrivate() && !$canSalary)
                                <div class="text-slate-500">Details need the salary permission.</div>
                            @else
                            @foreach ((array) $event->changes as $field => $change)
                                <div>
                                    <span class="text-slate-500">{{ \Illuminate\Support\Str::headline($field) }}:</span>
                                    @if (is_array($change))
                                        {{ $change[0] ?? '-' }} → {{ $change[1] ?? '-' }}
                                    @else
                                        {{ $change }}
                                    @endif
                                </div>
                            @endforeach
                            @if ($event->reason)
                                <div class="text-slate-500">{{ $event->reason }}</div>
                            @endif
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-slate-500">{{ $event->creatable?->userable?->displayName() ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
