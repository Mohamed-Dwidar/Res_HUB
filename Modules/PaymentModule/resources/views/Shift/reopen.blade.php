@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{--
    Reopen a closed shift (the account only, ShiftClosingAccountModuleController::reopenForm / reopen, 2026-10-26): its totals +
    a reason. Saved → its cash in / out go back to the login's open shift (closed again from My Shift / on behalf), the closing is
    removed, the reopen is logged (shift_events "reopen" with its totals). $error = why it can't be reopened (imported / not the
    last closing of the login): shown instead of the form. ?date= = back to that Daily Summary day.
--}}
@section('title')
    Reopen the Shift of {{ $closing->user?->userable?->displayName() ?? '-' }}?
@endsection

@section('content')
    <dl class="detail-list">
        <div><dt>Closed</dt><dd>{{ $closing->closed_at?->format('d-m-Y h:i A') }} · {{ $closing->closedBy?->userable?->displayName() ?? '-' }}</dd></div>
        <div><dt>From → To</dt><dd>@include('paymentmodule::Shift.partials.period')</dd></div>
    </dl>

    @if ($error)
        <div class="mt-5 text-danger">{{ $error }}</div>
        <div class="form-actions">
            @if (request()->ajax())
                <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Back</button>
            @else
                <a href="{{ route('account.shifts.show', $closing->id) }}" class="btn btn-secondary">Back</a>
            @endif
        </div>
    @else
        <div class="form-section mt-6">Totals</div>
        @include('paymentmodule::Shift.partials.totals', ['summary' => $closing->totals ?? []])

        <form method="POST" action="{{ route('account.shifts.reopen.store', $closing->id) }}" data-ajax class="mt-6"
            data-confirm="Its {{ $closing->count }} cash in and {{ $closing->totals['expenses']['count'] ?? 0 }} cash out go back to the open shift of {{ $closing->user?->userable?->displayName() ?? 'this login' }}, to be closed again."
            data-confirm-title="Reopen this shift?"
            data-confirm-button="Reopen"
            data-confirm-variant="danger">
            @csrf
            @method('DELETE')
            @include('paymentmodule::Shift.partials.back')

            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true,
                'attrs' => 'maxlength="255"', 'hint' => 'Why it is reopened (kept in the shift history).'])

            <div class="form-actions">
                <button type="submit" class="btn btn-danger"><i data-lucide="unlock"></i> Reopen Shift</button>
                @if (request()->ajax())
                    <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Cancel</button>
                @else
                    <a href="{{ route('account.shifts.show', $closing->id) }}" class="btn btn-secondary">Cancel</a>
                @endif
            </div>
        </form>
    @endif
@endsection
