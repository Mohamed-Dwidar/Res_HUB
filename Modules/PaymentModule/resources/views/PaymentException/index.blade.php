@php
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $timeFormat = \Modules\ReservationModule\app\Models\Reservation::TIME_FORMAT;
    $tabs = \Modules\PaymentModule\app\Services\PaymentExceptionService::TABS;
    $query = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'user_id' => $userId];
    $isExpense = fn ($row) => $row instanceof \Modules\PaymentModule\app\Models\Expense;
    // a link to the row's popup (a deleted one has no page)
    $rowLink = fn ($row) => $isExpense($row)
        ? ['url' => route('account.expenses.show', $row->id), 'text' => 'Cash Out #' . $row->expense_no]
        : ['url' => route('account.payments.show', $row->id), 'text' => $row->type_label . ' #' . $row->payment_no];
@endphp
@extends('layoutmodule::account.main')

{{--
    Payment Exceptions (2026-10-27, fraud prevention, the account only, read only — PaymentExceptionService): a date range (the
    recording / change day, default this month) and a login (who recorded the rows); per login counts, then one tab:
    Late Entries | Edited | Deleted | Expenses (employees' cash outs from the review amount, Settings › General) | Invoice Toggled
    ("Invoice requested" changed on a paid reservation, 2026-10-28).
--}}
@section('title')
    Payment Exceptions
@endsection

@section('content')
    <form method="GET" action="{{ route('account.payment-exceptions.index') }}" class="filter-bar">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="{{ config('layoutmodule.form.input') }} field-short" aria-label="From">
        <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="{{ config('layoutmodule.form.input') }} field-short" aria-label="To">
        <select name="user_id" class="{{ config('layoutmodule.form.select') }}" aria-label="Recorded by">
            <option value="">All Logins</option>
            @foreach ($users as $id => $name)
                <option value="{{ $id }}" @selected($userId === $id)>{{ $name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-secondary"><i data-lucide="search"></i> Show</button>
        <a href="{{ route('account.payment-exceptions.index', ['tab' => $tab]) }}" class="btn btn-secondary">Clear</a>
    </form>

    {{-- per login: the counts of the range (a number opens its tab for that login) --}}
    <div class="table-wrap mt-5">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Recorded By</th>
                    @foreach ($tabs as $key => $label)
                        <th class="text-right">{{ $key === 'expenses' ? 'Cash Out ≥ ' . number_format($reviewAmount, 2) : $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($counts as $id => $row)
                    <tr>
                        <td class="font-medium">{{ $users[$id] ?? 'User #' . $id }}</td>
                        @foreach ($tabs as $key => $label)
                            <td class="text-right">
                                @if ($row[$key] ?? 0)
                                    <a href="{{ route('account.payment-exceptions.index', ['tab' => $key, 'user_id' => $id] + $query) }}" class="font-medium">{{ $row[$key] }}</a>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($tabs) + 1 }}" class="text-slate-500">No exceptions from {{ $from->format($dateFormat) }} to {{ $to->format($dateFormat) }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <nav class="page-tabs mt-8">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('account.payment-exceptions.index', ['tab' => $key] + $query) }}" @class(['active' => $tab === $key])>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="mb-2 text-xs text-slate-500">
        @switch($tab)
            @case('late') Recorded more than {{ $lateHours }} h after the end of their business date (Settings › General). @break
            @case('edited') Edits of cash in / refunds / cash out and payments moved into a package (every edit is logged since 27-10-2026; before, only edits after a shift was closed). @break
            @case('deleted') Deleted cash in / refunds / cash out (only the account deletes). @break
            @case('expenses') Cash outs recorded by employees from {{ number_format($reviewAmount, 2) }} (Settings › General). @break
            @case('invoice') "Invoice requested" ticked / unticked on a reservation that was already paid (part or all): what the customer owes changed with its taxes. @break
            @case('unpaid_renewals') Periods renewed before the previous period was paid — by the owner or an employee (By), with the reason and what the member owed across their periods. @break
            @case('cancelled_paid') Reservations cancelled by the owner while they had payments (with the reason; the payments stay until refunded). @break
        @endswitch
        @if ($rows->count() >= \Modules\PaymentModule\app\Services\PaymentExceptionService::LIMIT)
            The newest {{ $rows->count() }} rows.
        @endif
    </div>

    <div class="table-wrap">
        <table class="data-table">
            @if ($tab === 'edited')
                <thead>
                    <tr><th>Changed</th><th>Record</th><th>Recorded By</th><th>Changed By</th><th>Changes</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $event)
                        <tr>
                            <td class="whitespace-nowrap">{{ $event->created_at->format($dateFormat) }}<div class="text-xs text-slate-500">{{ $event->created_at->format($timeFormat) }}</div></td>
                            <td class="whitespace-nowrap">
                                @if ($event->record && !$event->record->trashed())
                                    @php($link = $rowLink($event->record))
                                    <a href="{{ $link['url'] }}" data-modal class="font-medium">{{ $link['text'] }}</a>
                                @elseif ($event->record)
                                    {{ $rowLink($event->record)['text'] }} <span class="badge badge-status-danger">Deleted</span>
                                @else
                                    -
                                @endif
                                <div class="text-xs text-slate-500">{{ $event->action_label }}</div>
                            </td>
                            <td>{{ $event->user?->userable?->displayName() ?? '-' }}</td>
                            <td>{{ $event->byUser?->userable?->displayName() ?? '-' }}</td>
                            <td>
                                @forelse ($exceptionService->changedFields($event) as $label => [$before, $after])
                                    <div><span class="text-slate-500">{{ $label }}:</span> {{ $before ?? '-' }} → <span class="font-medium">{{ $after ?? '-' }}</span></div>
                                @empty
                                    <span class="text-slate-400">No field changed</span>
                                @endforelse
                            </td>
                            <td>{{ $event->reason ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-slate-500">Nothing in this range.</td></tr>
                    @endforelse
                </tbody>
            @elseif (in_array($tab, ['unpaid_renewals', 'cancelled_paid'], true))
                {{-- the owner's overrides (reservation_events, 2026-10-29) --}}
                <thead>
                    <tr>
                        <th>Date</th><th>Reservation</th><th>Member</th>
                        @if ($tab === 'unpaid_renewals')
                            <th>Unpaid Period</th><th class="text-right">It Owed</th><th class="text-right">New Period</th><th class="text-right">Total Owed</th>
                        @else
                            <th>Status</th><th class="text-right">Paid</th>
                        @endif
                        <th>Reason</th><th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $event)
                        <tr>
                            <td class="whitespace-nowrap">{{ $event->created_at->format($dateFormat) }}<div class="text-xs text-slate-500">{{ $event->created_at->format($timeFormat) }}</div></td>
                            <td class="whitespace-nowrap">
                                @if ($event->reservation && !$event->reservation->trashed())
                                    <a href="{{ route('account.reservations.show', $event->reservation_id) }}" data-modal class="font-medium">#{{ $event->reservation_id }}</a>
                                @else
                                    #{{ $event->reservation_id }} <span class="badge badge-status-danger">Deleted</span>
                                @endif
                            </td>
                            <td>{{ $event->reservation?->member?->name ?? '-' }}</td>
                            @if ($tab === 'unpaid_renewals')
                                <td><a href="{{ route('account.reservations.show', $event->related_reservation_id) }}" data-modal>#{{ $event->related_reservation_id }}</a></td>
                                <td class="whitespace-nowrap text-right text-danger">{{ number_format($event->amount, 2) }}</td>
                                <td class="whitespace-nowrap text-right">{{ number_format((float) ($event->data['new_amount'] ?? 0), 2) }}</td>
                                <td class="whitespace-nowrap text-right font-medium">{{ number_format((float) ($event->data['total'] ?? 0), 2) }}</td>
                            @else
                                <td class="whitespace-nowrap">{{ $event->data['from'] ?? '-' }} → <span class="font-medium">{{ $event->data['to'] ?? '-' }}</span></td>
                                <td class="whitespace-nowrap text-right font-medium">{{ number_format($event->amount, 2) }}</td>
                            @endif
                            <td>{{ $event->reason ?: '-' }}</td>
                            <td>{{ $event->recorder_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-slate-500">Nothing in this range.</td></tr>
                    @endforelse
                </tbody>
            @elseif ($tab === 'invoice')
                <thead>
                    <tr><th>Changed</th><th>Reservation</th><th>Member</th><th>Invoice</th><th class="text-right">Paid Then</th><th class="text-right">Costs Now</th><th>Changed By</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $log)
                        @php($record = $log->loggable)
                        <tr>
                            <td class="whitespace-nowrap">{{ $log->created_at->format($dateFormat) }}<div class="text-xs text-slate-500">{{ $log->created_at->format($timeFormat) }}</div></td>
                            <td class="whitespace-nowrap">
                                @if ($record)
                                    <a href="{{ route('account.reservations.show', $record->id) }}" data-modal class="font-medium">Reservation #{{ $record->id }}</a>
                                @else
                                    #{{ $log->loggable_id }} <span class="badge badge-status-danger">Deleted</span>
                                @endif
                            </td>
                            <td>{{ $record?->member?->name ?? '-' }}</td>
                            <td class="whitespace-nowrap">{{ $log->from_value ? 'Yes' : 'No' }} → <span class="font-medium">{{ $log->to_value ? 'Yes' : 'No' }}</span></td>
                            <td class="whitespace-nowrap text-right">{{ number_format($log->paid_amount, 2) }}</td>
                            <td class="whitespace-nowrap text-right font-medium">{{ $record ? number_format($record->dueAmount(), 2) : '-' }}</td>
                            <td>{{ $log->recorder_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-slate-500">Nothing in this range.</td></tr>
                    @endforelse
                </tbody>
            @else
                <thead>
                    <tr>
                        <th>{{ $tab === 'deleted' ? 'Deleted' : 'Recorded' }}</th>
                        <th>Record</th>
                        <th>Member / Item</th>
                        <th>Dated</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th class="text-right">Amount</th>
                        <th>Recorded By</th>
                        @if ($tab === 'deleted')
                            <th>Deleted By</th><th>Reason</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($date = $isExpense($row) ? $row->spent_at : $row->paid_at)
                        <tr>
                            @php($at = $tab === 'deleted' ? $row->deleted_at : $row->created_at)
                            <td class="whitespace-nowrap">{{ $at->format($dateFormat) }}<div class="text-xs text-slate-500">{{ $at->format($timeFormat) }}</div></td>
                            <td class="whitespace-nowrap">
                                @php($link = $rowLink($row))
                                @if ($tab === 'deleted')
                                    {{ $link['text'] }}
                                @else
                                    <a href="{{ $link['url'] }}" data-modal class="font-medium">{{ $link['text'] }}</a>
                                @endif
                            </td>
                            <td>
                                @if ($isExpense($row))
                                    {{ $row->item }}<div class="text-xs text-slate-500">{{ $row->expenseCategory?->name }}</div>
                                @else
                                    {{ $row->member?->name ?? '-' }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $date?->format($dateFormat) ?? '-' }}
                                @if ($tab !== 'late' && $row->isLateEntry())
                                    <div><span class="badge badge-status-warning">Late entry</span></div>
                                @endif
                            </td>
                            <td>{{ $isExpense($row) ? $row->method_name : ($row->paymentMethod?->name ?? '-') }}</td>
                            <td>{{ $row->reference ?: '-' }}</td>
                            <td class="whitespace-nowrap text-right font-medium">{{ number_format($isExpense($row) ? $row->amount : $row->signed_amount, 2) }}</td>
                            <td>{{ $row->recorder_name }}</td>
                            @if ($tab === 'deleted')
                                <td>{{ $row->deletedBy?->userable?->displayName() ?? '-' }}</td>
                                <td>{{ $row->delete_reason ?: '-' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $tab === 'deleted' ? 10 : 8 }}" class="text-slate-500">Nothing in this range.</td></tr>
                    @endforelse
                </tbody>
            @endif
        </table>
    </div>
@endsection
