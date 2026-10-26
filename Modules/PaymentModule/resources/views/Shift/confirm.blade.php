@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $employee ??= null;
@endphp
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{--
    The confirmation of "Close My Shift" (the app popup, not a browser confirm): the totals to hand over.
    The account: its own shift (account.shift.*), or an employee's ($employee) = closed on their behalf (logged),
    ?date= = back to that Daily Summary day.
    up_to_id = the highest payment shown: a payment added meanwhile → refused (ShiftClosingService::close()).
    $logout: opened from the logout popup → logged out after closing (custom.js, json "logout").
--}}
@section('title')
    {{ $employee ? 'Close the Shift of ' . $employee->userable->displayName() . '?' : 'Close My Shift?' }}
@endsection

@section('content')
    {{-- an empty shift closes too, with 0.00 (2026-10-27) --}}
    @php
        $empty = $period['payments']->isEmpty() && $period['expenses']->isEmpty();
    @endphp
    @if ($period['already_closed'])
        {{-- closed already in this business day and nothing new since (no second closing, 2026-10-27) --}}
        <div class="text-slate-500">This shift is already closed ({{ $period['last_closing']->closed_at->format('d-m-Y h:i A') }}), nothing new since then.</div>
    @else
        <div class="mb-4">
            @if ($empty)
                No cash in or cash out since the last closing: the shift closes with 0.00.
            @else
                {{ $period['summary']['count'] }} payments and {{ $period['summary']['expenses']['count'] }} expenses since {{ $period['since']?->format('d-m-Y h:i A') }}.
                After closing, only the account can still change them (with a reason, logged on the shift).
            @endif
            @if ($employee)
                It is logged as closed by you on their behalf.
            @endif
        </div>

        @unless ($empty)
            @include('paymentmodule::Shift.partials.totals', ['summary' => $period['summary']])
        @endunless

        <form method="POST" action="{{ route($employee ? 'account.shifts.close' : $area . '.shift.close') }}" data-ajax>
            @csrf
            <input type="hidden" name="up_to_id" value="{{ $period['summary']['max_id'] }}">
            <input type="hidden" name="up_to_expense_id" value="{{ $period['summary']['max_expense_id'] }}">
            @if ($employee)
                <input type="hidden" name="user_id" value="{{ $employee->id }}">
            @endif
            @include('paymentmodule::Shift.partials.back')
            @if ($logout)
                <input type="hidden" name="logout" value="1">
            @endif
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i data-lucide="lock"></i> Confirm Closing</button>
                @if (request()->ajax())
                    <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Cancel</button>
                @else
                    <a href="{{ $employee ? route('account.daily-summary.index', request()->only('date')) : route($area . '.shift.index') }}" class="btn btn-secondary">Cancel</a>
                @endif
            </div>
        </form>
    @endif
@endsection
