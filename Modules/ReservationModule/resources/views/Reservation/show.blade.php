@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{-- reservation no. + the package badge (opens the package); the member is in the content header (2026-11-16) --}}
    <span class="reservation-title">
        <div class="text-lg font-medium">Reservation #{{ $reservation->id }}</div>
        <div>
        @if ($reservation->package)
            <a href="{{ route($area . '.packages.show', $reservation->package_id) }}" data-modal class="badge badge-active mr-1" title="Package">
                <i data-lucide="package" class="inline h-3 w-3"></i> {{ $reservation->package->name }}
            </a>
        @endif
        </div>
    </span>
@endsection

@section('modal-size', 'xl')

{{-- buttons on top like the package view (2026-11-18, user): WhatsApp (icon) | status | Inv (invoice menu) | Pay (green; opens the
     Add Cash In fields of the Cash In block, while it owes) | Refund (only with a credit) | Extra | Renew | Restore | Edit | Delete;
     the Extras / Cash In blocks below have no buttons of their own here --}}
@section('actions')
    {{-- WhatsApp (MessagingModule): the suggested type by the reservation's state --}}
    @include('messagingmodule::Message.partials.button')
    {{-- the status: a drop menu like its badge, changed by ajax --}}
    @include('reservationmodule::Reservation.partials.status-menu')
    {{-- the combined invoice PDF (booking + extras; not inside a package: the package has it) --}}
    @include('paymentmodule::Payment.partials.invoice-button', ['payable' => $reservation, 'kind' => 'reservation', 'label' => 'Inv'])
    {{-- still owed, paid on its own (not inside a package / not counted): never more than the remaining --}}
    @if ($reservation->canTakePayments() && $reservation->remaining > 0 && Gate::allows('payments.create'))
        <button type="button" data-reveal="#cash-in-form" class="btn btn-pay"><i data-lucide="wallet"></i> Pay</button>
    @endif
    {{-- paid beyond the due: give the credit back (PaymentService::refundCredit()) --}}
    @if ($reservation->canTakePayments() && $reservation->remaining < 0 && Gate::allows('payments.refund'))
        <a href="{{ route($area . '.payments.credit-refund', ['reservation', $reservation->id]) }}" data-modal class="btn btn-secondary"><i data-lucide="undo-2"></i> Refund</a>
    @endif
    @if (!app(\Modules\PaymentModule\app\Services\ExtraService::class)->extrableError($reservation) && Gate::allows('extras.create'))
        <a href="{{ route($area . '.extras.create', ['reservation_id' => $reservation->id]) }}" data-modal class="btn btn-primary"><i data-lucide="plus"></i> Extra</a>
    @endif
    {{-- a counted subscription period not renewed yet: the next period, pre-filled (renewal_of = this one); an unpaid one too
         (2026-10-29: the Renew form shows what the member will owe and asks a reason) --}}
    @if ($reservation->canRenew() && Gate::allows('reservations.renew'))
        <a href="{{ route($area . '.reservations.create', ['renew' => $reservation->id]) }}" data-modal class="btn btn-secondary"><i data-lucide="refresh-cw"></i> Renew</a>
    @endif
    @unless ($reservation->isCounted() || Gate::denies('reservations.status'))
        <a href="{{ route($area . '.reservations.restore', [$reservation->id, 'back_to_view' => 1]) }}" data-modal class="btn btn-secondary"><i data-lucide="rotate-ccw"></i> Restore</a>
    @endunless
    {{-- roles (docs/roles-plan.md, R0): the buttons follow the employee's permissions (the owner: all) --}}
    @can('reservations.edit')
        <a href="{{ route($area . '.reservations.edit', $reservation->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @endcan
    @can('reservations.delete')
        @include('reservationmodule::Reservation.partials.delete-form')
    @endcan
@endsection

@section('content')
    {{-- info lines: place, plan, period (+ people), invoice, added by, notes (the amounts = the list below, 2026-11-16) --}}
    {{-- header: member (opens the member) + phone left, Due | Paid | Remaining right (paid on its own only) --}}
    <div class="flex flex-wrap items-center gap-4">
        <div>
            <div class="text-lg font-medium">@include('membermodule::partials.member-link', ['member' => $reservation->member])</div>
            <div class="mt-1 text-sm text-slate-500">{{ $reservation->member?->phone ?: '-' }}</div>
        </div>
        @include('paymentmodule::Payment.partials.money-box', ['payable' => $reservation])
    </div>

    {{-- two columns on large screens (stacked on phones): left = info, right = the Amounts card (2026-11-16, user) --}}
    <div class="view-columns mt-6">
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
            {{-- the renewal increase (2026-11-12): the next renewal's price until it is renewed --}}
            @if ($reservation->hasRenewalIncrease())
                <div class="mt-1 renewal-next-price">{{ $reservation->renewedBy ? 'Renewal increase: ' . $reservation->renewalIncreaseLabel() : 'Next renewal: ' . $reservation->nextRenewalText() }}</div>
            @endif
            {{-- a unit for 1 person (capacity 1, or 0 = not set): always 1, not shown (like the form) --}}
            @if ((int) $reservation->unit?->capacity > 1)
                <div class="mt-1 text-slate-500">Number of People: <b>{{ $reservation->number_of_peoples }}</b></div>
            @endif
            {{-- 2026-11-16 (user): the invoice badge (+ its VAT rule), added at / by, notes on top --}}
            @if ($reservation->invoice_requested && $reservation->isTaxed())
                <div class="mt-2"><span class="badge badge-status-info">Invoice requested</span> <span class="text-xs text-slate-500">{{ \Modules\PaymentModule\app\Services\Tax\TaxRule::fromSnapshot($reservation)?->label() }}</span></div>
            @elseif ($reservation->invoice_requested && $reservation->package_id)
                <div class="mt-2"><span class="badge badge-status-info">Invoice requested</span> <span class="text-xs text-slate-500">through the package</span></div>
            @endif
            <div class="mt-2 text-sm text-slate-500">Added by <b>{{ $reservation->creatable?->userable?->displayName() ?? $reservation->creatable?->email ?? '-' }}</b> | At {{ $reservation->created_at->format('d-m-Y h:i A') }}</div>
            @if ($reservation->notes)
                <div class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $reservation->notes }}</div>
            @endif
        </div>
        {{-- ONE invoice-style list of the amounts (2026-11-16, user; the invoice PDF's order) --}}
        @include('paymentmodule::Payment.partials.invoice-summary', ['payable' => $reservation])
    </div>

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

    {{-- extras charged on the reservation (2026-11-08: buffet, printing …; PaymentModule); inside a package: "add it on the package" --}}
    @if ($reservation->canHaveExtras() || !$reservation->canTakePayments() || $reservation->extrasTotal() != 0)
        @include('paymentmodule::Extra.partials.extras', ['extrable' => $reservation, 'kind' => 'reservation', 'buttons' => false])
    @endif

    {{-- the Cash In block without its Due / Paid / Remaining (shown above), + the Add Cash In fields (Pay / "+ Cash In") --}}
    @include('paymentmodule::Payment.partials.payable-payments', ['payable' => $reservation, 'kind' => 'reservation', 'summary' => false, 'methods' => $methods, 'buttons' => false])

    {{-- ContractModule (Phase 2): the contract covering this period, or Create / Renew Contract (only when its unit has a template on) --}}
    @include('contractmodule::Contract.partials.reservation-block')


    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.reservations.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Reservations</a>
        </div>
    @endunless
@endsection
