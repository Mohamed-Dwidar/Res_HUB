{{--
    The staff page's Leaves tab (docs/hr-plan.md §3.4, H3; hr.manage, not for External staff): the year (‹ 2026 ›), a balance
    card per type (left / entitled / taken; "Edit" = this person's own days), the year's leaves one line each (type · dates ·
    days · file · ⋮ Cancel; notes / by in the tooltip). $year, $leaves, $balances (LeaveService::balances()).
--}}
@php($fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.'))
<div class="hr-card">
    <div class="hr-card__head">
        <span class="leave-year">
            <a href="{{ route($area . '.hr.staff.show', [$staff->id, 'tab' => 'leaves', 'year' => $year - 1]) }}" class="btn btn-secondary btn-sm" title="{{ $year - 1 }}"><i data-lucide="chevron-left"></i></a>
            <span>Leaves {{ $year }}</span>
            <a href="{{ route($area . '.hr.staff.show', [$staff->id, 'tab' => 'leaves', 'year' => $year + 1]) }}" class="btn btn-secondary btn-sm" title="{{ $year + 1 }}"><i data-lucide="chevron-right"></i></a>
        </span>
        <a href="{{ route($area . '.hr.leaves.create', $staff->id) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Leave</a>
    </div>

    <div class="stat-boxes">
        @foreach ($balances as $typeId => $card)
            <div @class(['stat-box', 'leave-card', 'leave-card--over' => $card['left'] !== null && $card['left'] < 0])>
                <span>{{ $card['type']->name }}@unless ($card['type']->is_paid) · unpaid @endunless</span>
                @if ($card['entitled'] === null)
                    <b>{{ $fmt($card['taken']) }} <small>taken</small></b>
                    <span>no balance</span>
                @else
                    <b>{{ $fmt($card['left']) }} <small>left</small></b>
                    <span>of {{ $fmt($card['entitled']) }}{{ $card['own'] ? ' (own)' : '' }} · taken {{ $fmt($card['taken']) }}</span>
                @endif
                <a href="{{ route($area . '.hr.leaves.balance', [$staff->id, $typeId, 'year' => $year]) }}" data-modal class="leave-card__edit">Edit days</a>
            </div>
        @endforeach
    </div>
    @foreach ($balances as $card)
        @if ($card['left'] !== null && $card['left'] < 0)
            <div class="notice notice--warning mt-3">{{ $card['type']->name }} {{ $year }}: {{ $fmt(abs($card['left'])) }} day(s) over the balance.</div>
        @endif
    @endforeach

    <div class="advance-details__label">Leaves of {{ $year }}</div>
    @forelse ($leaves as $leave)
        <div @class(['adjustment-row', 'adjustment-row--muted' => $leave->trashed()])
            title="{{ $leave->notes ? $leave->notes . ' · ' : '' }}by {{ $leave->creatable?->userable?->displayName() ?? '-' }} · {{ $leave->created_at?->format('d-m-Y') }}{{ $leave->trashed() ? ' · Cancelled: ' . $leave->delete_reason : '' }}">
            <span class="badge {{ $leave->type?->is_paid ? 'badge-status-info' : 'badge-status-danger' }}">{{ $leave->type?->name ?? '-' }}</span>
            <span class="whitespace-nowrap">{{ $leave->datesText() }}</span>
            @if ($leave->attachment)
                <a href="{{ route($area . '.hr.leaves.file', [$staff->id, $leave->id]) }}" target="_blank" title="{{ $leave->attachment_name }}"><i data-lucide="paperclip" class="inline h-4 w-4"></i></a>
            @elseif ($leave->type?->needs_attachment && !$leave->trashed())
                <span class="badge badge-status-warning">No file</span>
            @endif
            <b class="adjustment-row__amount">{{ $leave->daysText() }}</b>
            <span class="adjustment-row__menu">
                @if ($leave->trashed())
                    <span class="text-xs text-slate-400">Cancelled</span>
                @else
                    @include('layoutmodule::partials.row-menu', ['links' => [
                        ['label' => 'Cancel', 'icon' => 'x-circle', 'url' => route($area . '.hr.leaves.cancel.form', [$staff->id, $leave->id]), 'modal' => true],
                    ]])
                @endif
            </span>
        </div>
    @empty
        <div class="text-slate-500">No leaves in {{ $year }}.</div>
    @endforelse
</div>
