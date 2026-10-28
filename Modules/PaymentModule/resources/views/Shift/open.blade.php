@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{--
    An OPEN shift (popup, the account only; 2026-10-28 — the Daily Summary Shifts names): the same parts as a closed shift's popup
    (Shift/show): since, totals, its cash in / refunds in the Payments list columns (row menu included), its cash out; + Close on
    Behalf (an employee) / Close My Shift (the account's own). ?date= = back to that Daily Summary day.
--}}
@section('title')
    Shift of {{ $user->userable?->displayName() ?? 'User #' . $user->id }}
    <span class="ml-2"><span class="badge badge-status-warning">Open</span></span>
@endsection

@section('actions')
    @if ($own)
        <a href="{{ route('account.shift.confirm') }}" data-modal class="btn btn-sm btn-secondary"><i data-lucide="lock"></i> Close My Shift</a>
    @else
        <a href="{{ route('account.shifts.confirm', ['user_id' => $user->id] + request()->only('date')) }}" data-modal class="btn btn-sm btn-secondary"><i data-lucide="lock"></i> Close on Behalf</a>
    @endif
@endsection

@section('modal-size', 'xl')

@section('content')
    <dl class="detail-list">
        <div><dt>Open Since</dt><dd>{{ $period['since']?->format('d-m-Y h:i A') ?? '-' }}</dd></div>
        <div><dt>Last Closing</dt><dd>{{ $period['last_closing']?->closed_at?->format('d-m-Y h:i A') ?? '-' }}</dd></div>
    </dl>

    @if ($payments->isEmpty() && $expenses->isEmpty())
        <div class="mt-6 text-slate-500">No cash in or cash out in this shift.</div>
    @else
        <div class="form-section mt-8">Totals</div>
        @include('paymentmodule::Shift.partials.totals', ['summary' => $period['summary']])

        <div class="form-section mt-8">Cash In and Refunds</div>
        @include('paymentmodule::Shift.partials.payments', ['payments' => $payments])

        @if ($expenses->isNotEmpty())
            <div class="form-section mt-8">Cash Out</div>
            @include('paymentmodule::Shift.partials.expenses', ['expenses' => $expenses])
        @endif
    @endif
@endsection
