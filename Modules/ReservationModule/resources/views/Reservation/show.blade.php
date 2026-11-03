@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{-- the member (name, phone under it) + the package badge (opens the package) --}}
    <span class="reservation-title">
        <div>
            <div class="text-lg font-medium">@include('membermodule::partials.member-link', ['member' => $reservation->member])</div>
            @if ($reservation->member?->phone)
                <div class="text-xs text-slate-500">{{ $reservation->member->phone }}</div>
            @endif
        </div>
        <div>
        @if ($reservation->package)
            <a href="{{ route($area . '.packages.show', $reservation->package_id) }}" data-modal class="badge badge-active mr-1" title="Package">
                <i data-lucide="package" class="inline h-3 w-3"></i> {{ $reservation->package->name }}
            </a>
        @endif
        </div>
    </span>
@endsection

@section('modal-size', 'lg')

@section('actions')
    {{-- WhatsApp (MessagingModule): the suggested type by the reservation's state --}}
    @include('messagingmodule::Message.partials.button')
    {{-- the status: a drop menu like its badge, changed by ajax --}}
    @include('reservationmodule::Reservation.partials.status-menu')
    @unless ($reservation->isCounted() || Gate::denies('reservations.status'))
        <a href="{{ route($area . '.reservations.restore', [$reservation->id, 'back_to_view' => 1]) }}" data-modal class="btn btn-secondary"><i data-lucide="rotate-ccw"></i> Restore</a>
    @endunless
    {{-- a counted subscription period not renewed yet: the next period, pre-filled (renewal_of = this one); an unpaid one too
         (2026-10-29: the Renew form shows what the member will owe and asks a reason) --}}
    @if ($reservation->canRenew() && Gate::allows('reservations.renew'))
        <a href="{{ route($area . '.reservations.create', ['renew' => $reservation->id]) }}" data-modal class="btn btn-secondary"><i data-lucide="refresh-cw"></i> Renew</a>
    @endif
    {{-- Pay: opens the Add Cash In fields of this view (Cash In block; still owed, paid on its own: not inside a package / not counted) --}}
    @if ($reservation->canTakePayments() && $reservation->remaining > 0 && Gate::allows('payments.create'))
        <button type="button" data-reveal="#cash-in-form" class="btn btn-pay"><i data-lucide="wallet"></i> Pay</button>
    @endif
    {{-- roles (docs/roles-plan.md, R0): the buttons follow the employee's permissions (the owner: all) --}}
    @can('reservations.edit')
        <a href="{{ route($area . '.reservations.edit', $reservation->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @endcan
    @can('reservations.delete')
        @include('reservationmodule::Reservation.partials.delete-form')
    @endcan
@endsection

@section('content')
    {{-- the place, plan, period (+ people) on the left; Due | Paid | Remaining on the right like the package view (2026-11-01, user),
         only when it is paid on its own (not inside a package / not counted) --}}
    <div class="flex flex-wrap items-start gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($reservation->unit?->color)
                    @include('unitmodule::partials.color-swatch', ['value' => $reservation->unit->color->value])
                @endif
                {{ $reservation->space?->name ?? '-' }}
                &rsaquo;
                {{ $reservation->unit?->name ?? '-' }}
            </div>
            {{-- the plan under the place --}}
            <div class="mt-1 text-slate-500">{{ $reservation->plan?->name ?? '-' }}</div>
            {{-- the period (the status is in the header menu) --}}
            <div class="mt-2 text-slate-500">{{ $reservation->period }}</div>
            {{-- a unit for 1 person (capacity 1, or 0 = not set): always 1, not shown (like the form) --}}
            @if ((int) $reservation->unit?->capacity > 1)
                <div class="mt-1 text-slate-500">Number of People: <b>{{ $reservation->number_of_peoples }}</b></div>
            @endif
        </div>
        @if ($reservation->canTakePayments())
            @php($remaining = (float) $reservation->remaining)
            <div class="money-box ml-auto">
                <div class="money-box__item money-box__item--due" title="{{ $reservation->isTaxed() ? 'Total incl. taxes' : 'Net amount' }}">
                    <span>Due</span><b>{{ number_format($reservation->payableAmount(), 2) }}</b>
                </div>
                <div class="money-box__item money-box__item--paid">
                    <span>Paid</span><b>{{ number_format($reservation->paid_amount, 2) }}</b>
                </div>
                <div class="money-box__item {{ $remaining > 0 ? 'money-box__item--owed' : ($remaining < 0 ? 'money-box__item--credit' : 'money-box__item--paid') }}">
                    <span>{{ $remaining < 0 ? 'Credit' : 'Remaining' }}</span><b>{{ number_format(abs($remaining), 2) }}</b>
                </div>
            </div>
        @endif
    </div>

   <div class="form-section">&nbsp;</div>

    {{-- Amount - Discount - Net Amount (+ the taxes); Paid / Remaining are in the box at the top --}}
    @include('paymentmodule::Payment.partials.amount-lines', ['payable' => $reservation, 'kind' => 'reservation', 'paidLine' => false])

    {{-- a subscription period: the period it renews, its renewal, left (+ undo) --}}
    @if ($reservation->isPeriod() && ($reservation->renewal_of || $reservation->renewedBy || $reservation->left_at))
        <div class="form-section">&nbsp;</div>
        <dl class="detail-list">
            @if ($reservation->renewal_of)
                <div><dt>Renewal Of</dt><dd><a href="{{ route($area . '.reservations.show', $reservation->renewal_of) }}" data-modal class="font-medium">#{{ $reservation->renewal_of }}</a></dd></div>
            @endif
            @if ($renewal = $reservation->renewedBy)
                <div><dt>Renewed By</dt><dd>
                    <a href="{{ route($area . '.reservations.show', $renewal->id) }}" data-modal class="font-medium">#{{ $renewal->id }}</a>
                    → {{ $renewal->realEnd()?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}
                </dd></div>
            @endif
            @if ($reservation->left_at)
                <div><dt>Left</dt><dd>
                    {{ $reservation->left_at->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}
                    {{ $reservation->left_by ? '' : '(auto, old data)' }}
                    @include('reservationmodule::Reservation.partials.left-form', ['undo' => true, 'backToView' => true])
                </dd></div>
            @endif
        </dl>
    @endif

    {{-- the Cash In block without its Due / Paid / Remaining (shown above), + the Add Cash In fields (Pay / "+ Cash In") --}}
    @include('paymentmodule::Payment.partials.payable-payments', ['payable' => $reservation, 'kind' => 'reservation', 'summary' => false, 'methods' => $methods])

    {{-- ContractModule (Phase 2): the contract covering this period, or Create / Renew Contract (only when its unit has a template on) --}}
    @include('contractmodule::Contract.partials.reservation-block')

    <div class="form-section">&nbsp;</div>

    <dl class="detail-list">
        <div class="wide"><dt>Notes</dt><dd class="whitespace-pre-line">{{ $reservation->notes ?: '-' }}</dd></div>
        <div class="wide">
            <dd class="whitespace-pre-line">
                Added by <b>{{ $reservation->creatable?->userable?->displayName() ?? $reservation->creatable?->email ?? '-' }}</b>
                |
                At {{ $reservation->created_at->format('d-m-Y h:i A') }}
            </dd>
        </div>
    </dl>

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.reservations.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Reservations</a>
        </div>
    @endunless
@endsection
