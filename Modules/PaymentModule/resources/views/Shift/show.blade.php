@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{--
    A closed shift (popup): its totals (recalculated when the account edits / deletes one of its payments), its payments in the
    Payments list columns (row menu included). No history here (user 2026-10-13; the changes stay logged in shift_events).
    The account changes a payment directly from its row menu, with a reason; "Reopen" (the account only, 2026-10-26; not an imported
    closing) opens Shift/reopen (reason → its rows back to the open shift; refused there when it is not the login's last closing).
--}}
@section('title')
    Shift of {{ $closing->user?->userable?->displayName() ?? '-' }}
    <span class="ml-2">@include('paymentmodule::Shift.partials.status')</span>
@endsection

@section('actions')
    @if ($area === 'account' && !$closing->is_imported)
        <a href="{{ route('account.shifts.reopen', ['id' => $closing->id] + request()->only('date')) }}" data-modal class="btn btn-sm btn-warning"><i data-lucide="unlock"></i> Reopen</a>
    @endif
@endsection

@section('modal-size', 'xl')

@section('content')
    <dl class="detail-list">
        <div><dt>Closed</dt><dd>{{ $closing->closed_at?->format('d-m-Y h:i A') }} · {{ $closing->closedBy?->userable?->displayName() ?? '-' }}</dd></div>
        <div><dt>From → To</dt><dd>@include('paymentmodule::Shift.partials.period')</dd></div>
        @if ($closing->notes)
            <div><dt>Notes</dt><dd>{{ $closing->notes }}</dd></div>
        @endif
        @if ($closing->isEdited())
            <div><dt>Edited After Closing</dt><dd class="text-warning">{{ $closing->edit_count }} change(s), last {{ $closing->edited_at?->format('d-m-Y h:i A') }}</dd></div>
        @endif
    </dl>

    <div class="form-section mt-8">Totals</div>
    @include('paymentmodule::Shift.partials.totals', ['summary' => $closing->totals ?? []])

    <div class="form-section mt-8">Cash In and Refunds</div>
    @include('paymentmodule::Shift.partials.payments', ['payments' => $closing->payments])

    @if ($closing->expenses->isNotEmpty())
        <div class="form-section mt-8">Cash Out</div>
        @include('paymentmodule::Shift.partials.expenses', ['expenses' => $closing->expenses])
    @endif
@endsection
