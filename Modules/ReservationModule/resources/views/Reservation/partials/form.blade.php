{{--
    Reservation create / edit fields. $reservation is null on create (or not saved yet: a renewal pre-filled from a subscription). Every drop menu is searchable (Tom Select).
    The drop menus chain: subscription type -> spaces -> units -> plans, all sent with the form ($placeTree, ReservationService::placeTree()
    in [data-place-tree]) and filtered in the browser (custom.js select[data-place], no request); a list with only one (active)
    option selects it (data-auto-single), so its own list follows. Unit + dates + times = the live availability line
    ([data-availability], {area}.reservations.availability); saving checks it again.
    The package locks the member, the plan fills the amount; discount % / discount value / net amount calculate each other
    (custom.js "Reservation form"), the server calculates them again (and the subscription day from the start date).
    The status is not chosen here (a new reservation gets the first active status, it is changed in another place).
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    // the unit capacity (data-capacity): 1 or 0 = the number of people is 1, the field is hidden (custom.js reservationCapacity())
    $unitOptions = collect($units)->mapWithKeys(fn ($unit) => [$unit['id'] => ['name' => $unit['name'], 'data' => ['capacity' => $unit['capacity']]]])->all();
    // data-time-based: the plan reservations have a start / end time (custom.js shows the time inputs)
    // + the plan's tax type / "Invoice by default" (custom.js reservationTax(), docs/payments-plan.md §3)
    $planOptions = collect($plans)->mapWithKeys(fn ($plan) => [$plan['id'] => ['name' => $plan['name'], 'data' => ['amount' => $plan['amount'], 'time-based' => $plan['timeBased'], 'period-hours' => $plan['periodHours'], 'lease-period' => $plan['leasePeriod'],
        'vat-exempt' => $plan['vatExempt'], 'table-tax' => $plan['tableTax'], 'inclusive' => $plan['inclusive'], 'invoice-default' => $plan['invoiceDefault']]]])->all();
    $packageOptions = collect($packages)->map(fn ($package) => ['name' => $package['name'], 'data' => ['member-id' => $package['member_id'], 'member-text' => $package['member_text']]])->all();
    // the date / time inputs need Y-m-d / H:i values (the browser shows them in the user's format)
    // create: today, now -> in 1 hour (pre-filled from the Schedule page: its start -> in 1 hour); edit / renew: the saved values
    // (the times only for a time based plan)
    $now = now();
    $prefilled = $reservation && !$reservation->exists && !$reservation->plan_id;
    $fresh = !$reservation || $prefilled;
    $startDefault = $prefilled && $reservation->start_at ? $reservation->start_at : $now;
    $endDefault = $startDefault->copy()->addHour();
    $timeBased = (bool) $reservation?->plan?->is_time_based;
    $startAt = old('start_at', $fresh ? $startDefault->format('Y-m-d') : $reservation->start_at?->format('Y-m-d'));
    $endAt = $fresh ? $endDefault->format('Y-m-d') : $reservation->end_at?->format('Y-m-d');
    $startTime = old('start_time', $fresh ? $startDefault->format('H:i') : ($timeBased ? $reservation->start_at?->format('H:i') : null));
    $endTime = old('end_time', $fresh ? $endDefault->format('H:i') : ($timeBased ? $reservation->end_at?->format('H:i') : null));
    // a new reservation at night (before the cutoff of Settings › General): a full day one is for the business date (the day
    // before), a time based one for now — custom.js reservationTimeBased() switches the untouched dates with the plan
    $nightDates = '';
    $businessDate = \Modules\AccountModule\app\Models\Account::current()?->businessDate();
    if (!$reservation && $businessDate && !$businessDate->isSameDay($now)) {
        $nightDates = ' data-business-date="' . $businessDate->format('Y-m-d') . '"';
        $startAt = old('start_at', $businessDate->format('Y-m-d'));
        $endAt = $businessDate->format('Y-m-d');
    }
    // "Renewal of #": the account's edit form only (a mistake fix); the Renew form sends it hidden
    $renewalField = $reservation?->exists && $area === 'account' && $reservation->isPeriod();
    // the Renew form (?renew=ID): member, package and subscription type stay the renewed period's (hidden, shown as text; the
    // service enforces them too), no repeat; place, plan, dates and amounts can change
    $renewing = $reservation && !$reservation->exists && $reservation->renewal_of;
    $renewed = $renewing ? $reservation->renewalOf : null;
    // opened from the Schedule page (+ New / a timeline slot: ?subscription_type_id=): the type is fixed (hidden, with its repeat
    // flag for custom.js reservationTypeOptions()), the popup title names it (create.blade.php)
    $fixedType = $reservation && !$reservation->exists && !$renewing && $reservation->subscription_type_id
        && isset($subscriptionTypes[$reservation->subscription_type_id]) ? $subscriptionTypes[$reservation->subscription_type_id] : null;
    // "Book For": a saved reservation in a package opens on Package
    $bookFor = old('book_for', $reservation?->package_id ? 'package' : 'member');
    // the anchor day of the Renew / edit form (custom.js reservationEndFromPlan(): a month plan ends by it; new = the start day)
    $anchorDay = ($renewing || $reservation?->exists) && $reservation->subscription_day ? $reservation->subscription_day : null;
@endphp

@if ($renewing)
    <input type="hidden" name="renewal_of" value="{{ $reservation->renewal_of }}">
    <input type="hidden" name="member_id" value="{{ $reservation->member_id }}">
    <input type="hidden" name="package_id" value="{{ $reservation->package_id ?: '' }}">
    <input type="hidden" name="subscription_type_id" value="{{ $reservation->subscription_type_id }}">
    <div class="mb-5 text-primary">
        Renewal of <b>{{ $reservation->member?->name ?? '-' }}</b>{{ $renewed?->unit ? ' · ' . $renewed->unit->name : '' }}:
        the current period ends {{ $renewed?->realEnd()?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}, the new one starts the day after.
    </div>
    {{-- renewing a period that still owes (2026-10-29, owner AND employees): what the member will owe across their periods + a
         required reason (ReservationService::unpaidRenewalNote(): the new period's notes + reservation_events → Payment Exceptions) --}}
    @if ($renewed && $renewed->renewalDebt() > 0)
        @php
            $owing = \Modules\ReservationModule\app\Models\Reservation::owingPeriodsOf($renewed->account_id, $renewed->member_id);
            $newAmount = (float) $reservation->net_amount;
        @endphp
        <div class="mb-5 tax-warning">
            <div class="font-medium">#{{ $renewed->id }} is not fully paid. This member will owe {{ number_format($owing->sum('remaining') + $newAmount, 2) }} across {{ $owing->count() + 1 }} periods:</div>
            @foreach ($owing as $period)
                <div>#{{ $period->id }} · {{ $period->start_at?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }} → {{ $period->realEnd()?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }} · still owes {{ number_format($period->remaining, 2) }}</div>
            @endforeach
            <div>This renewal · {{ number_format($newAmount, 2) }}</div>
        </div>
        <div class="mb-5">
            @include('layoutmodule::partials.field', ['name' => 'unpaid_renewal_reason', 'label' => 'Why Renew Before It Is Paid?', 'type' => 'textarea', 'required' => true,
                'attrs' => 'maxlength="255"', 'hint' => 'Kept in the new period\'s notes and listed in Payment Exceptions › Unpaid Renewals with your name.'])
        </div>
    @endif
    <div class="form-section">Member</div>
    <dl class="detail-list">
        <div><dt>Member</dt><dd>{{ $reservation->member?->name ?? '-' }} @if ($reservation->member?->phone)<span class="text-xs text-slate-500">{{ $reservation->member->phone }}</span>@endif</dd></div>
        @if ($reservation->package_id)
            <div><dt>Package</dt><dd>{{ $reservation->package?->name ?? '#' . $reservation->package_id }}</dd></div>
        @endif
    </dl>
@else
{{-- "Book for" decides the next field (user 2026-10-22): Member → the Member field + "New member" | Package → the package
     list, its member shown as text under it (custom.js reservationLockMember(); the server takes the package's member) --}}
<div class="form-section">Member</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'book_for', 'label' => 'Book For', 'type' => 'select', 'required' => true,
        'options' => ['member' => 'Member', 'package' => 'Package'], 'value' => $bookFor])
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-1" data-show-if="book_for=package">
    {{-- as wide as the Member field (user 2026-10-22) --}}
    @include('layoutmodule::partials.field', ['name' => 'package_id', 'label' => 'Package', 'type' => 'select', 'required' => true, 'searchable' => true,
        'width' => 'long', 'options' => ['' => '- Select -'] + $packageOptions, 'value' => $reservation?->package_id ?: null,
        'hint' => 'Search by package or member name.'])
    <div class="package-member" data-package-member hidden>Member: <b data-package-member-name></b></div>
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-1" data-show-if="book_for=member">
    {{-- server search + quick add (membermodule::partials.member-select) --}}
    @include('membermodule::partials.member-select', ['member' => $reservation?->member, 'required' => true, 'companies' => $companies])
</div>
@endif

<div class="form-section mt-8">Place & Plan</div>
{{-- every active space / unit / plan of the account by type (custom.js placeOptions()) --}}
<script type="application/json" data-place-tree>@json($placeTree ?? ['spaces' => [], 'units' => [], 'plans' => []])</script>
@if ($fixedType)
    <input type="hidden" name="subscription_type_id" value="{{ $reservation->subscription_type_id }}" data-can-repeat="{{ $fixedType['data']['can-repeat'] }}">
@endif
@unless ($renewing || $fixedType)
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'subscription_type_id', 'label' => 'Subscription Type', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $subscriptionTypes, 'value' => $reservation?->subscription_type_id ?: null,
        'hint' => empty($subscriptionTypes) ? 'No subscription types yet, add them from Spaces > Subscription Types.' : 'The spaces with this subscription type are shown.'])
</div>
@endunless
<div class="grid grid-cols-1 gap-5 {{ $renewing || $fixedType ? '' : 'mt-5' }} md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'space_id', 'label' => 'Space', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $spaces, 'value' => $reservation?->space_id,
        'attrs' => 'data-place="spaces" data-parent="subscription_type_id" data-auto-single data-none="No spaces with this subscription type"' . (empty($spaces) ? ' disabled' : '')])
    @include('layoutmodule::partials.field', ['name' => 'unit_id', 'label' => 'Unit', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $unitOptions, 'value' => $reservation?->unit_id,
        'attrs' => 'data-place="units" data-parent="space_id" data-also="subscription_type_id" data-auto-single data-none="No active units for this space and subscription type"' . (empty($unitOptions) ? ' disabled' : '')])
    @include('layoutmodule::partials.field', ['name' => 'plan_id', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'searchable' => true,
        'options' => ['' => '- Select -'] + $planOptions, 'value' => $reservation?->plan_id,
        'attrs' => 'data-place="plans" data-parent="unit_id" data-auto-single data-none="No plans for this unit"' . (empty($planOptions) ? ' disabled' : ''),
        'hint' => 'The amount is filled from the plan.'])
    {{-- the Renew form keeps the renewed period's number of people (hidden) --}}
    @if ($renewing)
        <input type="hidden" name="number_of_peoples" value="{{ $reservation->number_of_peoples ?: 1 }}">
    @else
        @include('layoutmodule::partials.field', ['name' => 'number_of_peoples', 'label' => 'Number of People', 'type' => 'number', 'required' => true,
        'value' => $reservation?->number_of_peoples ?? 1, 'attrs' => 'min="1" step="1" max="100000"', 'hint' => 'Not more than the unit capacity.'])
    @endif
</div>

<div class="form-section mt-8">Dates</div>
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    <div class="date-time-pair">
        @include('layoutmodule::partials.field', ['name' => 'start_at', 'label' => 'Start Date', 'type' => 'date', 'required' => true, 'value' => $startAt,
            'attrs' => $nightDates ? $nightDates . ' data-real-date="' . $now->format('Y-m-d') . '"' : ''])
        {{-- only for a time based plan (custom.js [data-time-fields]) --}}
        <div data-time-fields>
            @include('layoutmodule::partials.field', ['name' => 'start_time', 'label' => 'Start Time', 'type' => 'time', 'required' => true, 'value' => $startTime])
        </div>
    </div>
{{-- the Renew form: start and end on one line --}}
@unless ($renewing)
</div>
<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-3">
@endunless
    <div class="date-time-pair">
        {{-- always required ("The reservation continues" removed 2026-10-15: a subscription is renewed by a new period, renewal_of) --}}
        @include('layoutmodule::partials.field', ['name' => 'end_at', 'label' => 'End Date', 'type' => 'date', 'required' => true,
            'value' => old('end_at', $endAt),
            'attrs' => 'data-default-from="start_at"' . ($anchorDay ? ' data-anchor="' . $anchorDay . '"' : '')
                . ($nightDates ? $nightDates . ' data-real-date="' . $endDefault->format('Y-m-d') . '"' : '')])
        <div data-time-fields>
            @include('layoutmodule::partials.field', ['name' => 'end_time', 'label' => 'End Time', 'type' => 'time', 'required' => true, 'value' => $endTime])
        </div>
    </div>
</div>
{{-- the live availability of the unit in these dates / times (custom.js reservationAvailability(); saving checks it again) --}}
<div class="availability-line" data-availability data-url="{{ route($area . '.reservations.availability') }}"
    @if ($reservation?->exists) data-ignore="{{ $reservation->id }}" @endif hidden></div>

<div class="form-section mt-8">Amount</div>
{{-- discount_type = the one typed last (percentage | value | net), the server keeps it and calculates the others --}}
<input type="hidden" name="discount_type" value="{{ old('discount_type', 'percentage') }}">
<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'value' => $reservation?->amount ?? 0,
        'attrs' => 'min="0" step="0.01" max="99999999"'])
    @include('layoutmodule::partials.field', ['name' => 'discount_percentage', 'label' => 'Discount %', 'type' => 'number', 'value' => $reservation?->discount_percentage ?? 0,
        'attrs' => 'min="0" max="100" step="0.01"'])
    @include('layoutmodule::partials.field', ['name' => 'discount_value', 'label' => 'Discount Value', 'type' => 'number', 'value' => $reservation?->discount_value ?? 0,
        'attrs' => 'min="0" step="0.01"'])
    @include('layoutmodule::partials.field', ['name' => 'net_amount', 'label' => 'Net Amount', 'type' => 'number', 'value' => $reservation?->net_amount ?? 0,
        'attrs' => 'min="0" step="0.01" data-net', 'hint' => 'Change the discount % / value or the net, the others are calculated.'])
</div>

{{-- Taxes only when the customer requests an invoice (docs/payments-plan.md §3), once taxes apply to this reservation (the tax
     start date; a saved one keeps its tax type): the checkbox (new = the plan's "Invoice by default", Renew = the renewed
     period's), the live breakdown and the "will owe X more" warning on a paid one (custom.js reservationTax(); the server
     recalculates). Inside a package the package's invoice counts (Phase C). --}}
@php
    $taxAccount = \Modules\AccountModule\app\Models\Account::current();
    $taxesOn = $taxAccount && ($reservation?->exists
        ? \Modules\PaymentModule\app\Services\Tax\TaxSnapshot::applies($taxAccount, $reservation)
        : $taxAccount->taxesApplyTo(now()));
    $storedRule = $reservation?->exists ? \Modules\PaymentModule\app\Services\Tax\TaxRule::fromSnapshot($reservation) : null;
    // created before the (back-dated) start date was set: the owner only, never pre-ticked (Account::isBackdated())
    $backdated = $taxesOn && $reservation?->exists && $taxAccount->isBackdated($reservation->created_at);
    $taxesOn = $taxesOn && !($backdated && $area !== 'account');
@endphp
@if ($taxesOn)
    <div class="tax-box" data-tax-box data-vat-rate="{{ $taxAccount->vatRate() }}" data-table-rate="{{ $taxAccount->tableTaxRate() }}"
        data-rounding="{{ $taxAccount->taxRounding() }}" data-keep="{{ $reservation?->exists || $renewing ? 1 : 0 }}"
        data-in-package="{{ $renewing && $reservation->package_id ? 1 : 0 }}"
        @if ($storedRule) data-stored-plan="{{ $reservation->plan_id }}"
            data-stored="{{ json_encode(['vat' => $storedRule->vatBasisPoints(), 'table' => $storedRule->tableBasisPoints(), 'inclusive' => $storedRule->inclusive, 'rounding' => $storedRule->rounding]) }}" @endif
        @if ($reservation?->exists) data-paid="{{ (float) $reservation->paid_amount }}" data-due="{{ $reservation->dueAmount() }}" data-was="{{ $reservation->invoice_requested ? 1 : 0 }}" @endif>
        @unless ($renewing && $reservation->package_id)
            <div @unless ($renewing) data-show-if="book_for=member" @endunless>
                @include('layoutmodule::partials.checkbox', ['name' => 'invoice_requested', 'label' => 'Invoice requested',
                    'checked' => (bool) $reservation?->invoice_requested,
                    'hint' => 'Taxes are added only when the customer asks for an invoice (the plan sets which taxes).'])
            </div>
        @endunless
        @if ($backdated)
            <div class="{{ config('layoutmodule.form.hint') }}">Made before taxes were set up (back-dated start date): tick it only if the customer asks for an invoice. Employees don't see it here.</div>
        @endif
        <div class="tax-breakdown" data-tax-breakdown></div>
        <div class="tax-warning" data-tax-warning hidden></div>
        {{-- the double-tax guard: the member's VAT (manual) cash ins near the dates, while "Invoice requested" is ticked --}}
        <div class="tax-warning" data-tax-vat data-url="{{ route($area . '.reservations.manual-vat') }}" hidden></div>
    </div>
@endif

{{-- only when the subscription type can repeat (custom.js [data-type-allows]) --}}
{{-- on create only: the copies are made when it is saved (not changed on edit) --}}
@unless ($reservation?->exists || $renewing)
    <div data-type-allows="can-repeat">
        <div class="form-section mt-8">Repeat</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-4">
            <div class="md:pt-8">
                @include('layoutmodule::partials.checkbox', ['name' => 'is_repeat', 'label' => 'Repeat this reservation', 'checked' => false])
            </div>
            <div data-show-if="is_repeat">
                @include('layoutmodule::partials.field', ['name' => 'repeat_frequency', 'label' => 'Repeat Frequency', 'type' => 'select', 'searchable' => true, 'required' => true,
                    'options' => \Modules\ReservationModule\app\Models\Reservation::REPEAT_FREQUENCIES, 'value' => 'weekly'])
            </div>
            <div data-show-if="is_repeat">
                @include('layoutmodule::partials.field', ['name' => 'repeat_interval', 'label' => 'Repeat Every', 'type' => 'number', 'required' => true,
                    'value' => 1, 'attrs' => 'min="1" step="1" max="365"', 'hint' => 'e.g. 2 with weekly = every 2 weeks.'])
            </div>
            <div data-show-if="is_repeat">
                @include('layoutmodule::partials.field', ['name' => 'repeat_until', 'label' => 'Repeat Until', 'type' => 'date', 'required' => true,
                    'hint' => 'A copy of the reservation is added for each repeat until this date.'])
            </div>
        </div>
    </div>
@endunless

@if ($renewalField)
    <div class="form-section mt-8">Renewal</div>
    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
        @include('layoutmodule::partials.field', ['name' => 'renewal_of', 'label' => 'Renewal of #', 'type' => 'number', 'value' => $reservation->renewal_of,
            'attrs' => 'min="1" step="1"', 'hint' => 'The reservation number of the period this one renews (empty = none). Same subscription type, not renewed by another one.'])
        {{-- the anchor day (subscription_day, 2026-10-23): a monthly renewal starts on this day of the month (the month's last day when shorter) --}}
        @include('layoutmodule::partials.field', ['name' => 'subscription_day', 'label' => 'Billing day', 'type' => 'number',
            'value' => old('subscription_day', $reservation->subscription_day ?: $reservation->start_at?->day),
            'attrs' => 'min="1" max="31" step="1"', 'hint' => 'The day of the month the subscription renews on (31 = the last day of a shorter month).'])
    </div>
@endif

<div class="mt-5">
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $reservation?->notes])
</div>
