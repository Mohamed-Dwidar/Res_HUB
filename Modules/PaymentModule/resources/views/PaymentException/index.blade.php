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
    Late Entries | Edited | Deleted | Expenses (employees' cash outs from the review amount, Settings › General).
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
