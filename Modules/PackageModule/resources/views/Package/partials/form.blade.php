{{-- Package create / edit fields. $package is null on create. $companies = the quick add member's companies --}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    {{-- Add Package: suggested from the chosen member ("<member> - <m-Y>[ - N]", custom.js packageNameSuggest) until typed by hand --}}
    @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $package?->name,
        'attrs' => 'autofocus' . ($package ? '' : ' data-name-suggest="' . route((request()->routeIs('employee.*') ? 'employee' : 'account') . '.packages.suggest-name') . '"'),
        'hint' => $package ? null : 'Filled from the member: "Member - month" (+ " - 1", " - 2" … when it is taken).'])
    {{-- server search + quick add (membermodule::partials.member-select) --}}
    @include('membermodule::partials.member-select', ['member' => $package?->member, 'required' => true, 'width' => 'xlong', 'wrapperClass' => 'md:col-span-2',
        'hint' => 'Search by name or mobile. The reservations of this package are for this member.', 'companies' => $companies])
</div>

{{-- Price / discount / net: edit only (a new package has no reservations yet, user 2026-10-21) --}}
@if ($package)
<div class="form-section mt-8">Price</div>
{{-- the price = the net amounts of the package reservations (calculated, not typed); the discount (2026-11-17, user): Discount Type
     = Percentage or Fixed amount, only its input is shown; the discount and the net change each other (custom.js packageCalculate()) --}}
@php($discountType = old('discount_type', (float) $package->discount_fixed > 0 ? 'fixed' : 'percentage'))
{{-- one per line (user 2026-11-17): Price, Discount, Net — the discount and the net change each other (custom.js packageCalculate()) --}}
<div class="grid grid-cols-1 gap-5" data-package-price>
    <input type="hidden" name="net_typed" value="0" data-calc="net-typed">
    @include('layoutmodule::partials.field', ['name' => 'amount_display', 'label' => 'Price', 'type' => 'number', 'value' => $package->reservationsTotal(),
        'attrs' => 'readonly tabindex="-1" data-calc="amount"', 'hint' => 'The net amounts of its reservations.'])
    @include('layoutmodule::partials.discount-field', ['type' => $discountType, 'percentage' => $package->discount_percentage ?? 0,
        'fixed' => $package->discount_fixed ?? 0, 'calc' => true])
    @include('layoutmodule::partials.field', ['name' => 'after_discount', 'label' => 'Net', 'type' => 'number',
        'value' => $package->after_discount ?? 0, 'attrs' => 'min="0" step="0.01" max="99999999" data-calc="result"', 'hint' => 'Change the discount or the net: the other follows.'])
</div>

{{-- "Invoice requested" (taxes Phase C, docs/payments-plan.md §3): ONE switch for the package — its reservations and extras
     follow it; only while taxes apply to it (PackageService::canSetInvoice(): back-dated = the owner only). The amounts with /
     without an invoice at the saved discount (PackageTax::preview()); the server recalculates on save. --}}
@php($packageService = app(\Modules\PackageModule\app\Services\PackageService::class))
@if ($packageService->canSetInvoice($package, Auth::user()))
    @php($taxAccount = \Modules\AccountModule\app\Models\Account::settingsOf($package->account_id))
    @php($preview = app(\Modules\PaymentModule\app\Services\Tax\PackageTax::class)->preview($package, $taxAccount))
    @php($current = $package->payableAmount())
    <div class="form-section mt-8">Invoice</div>
    <div class="tax-box">
        @include('layoutmodule::partials.checkbox', ['name' => 'invoice_requested', 'label' => 'Invoice requested',
            'checked' => (bool) $package->invoice_requested,
            'hint' => 'Taxes are added only when the customer asks for an invoice. Its reservations and extras follow this switch; the discount is applied before tax.'])
        @if ($taxAccount?->isBackdated($package->created_at))
            <div class="{{ config('layoutmodule.form.hint') }}">Made before taxes were set up (back-dated start date): tick it only if the customer asks for an invoice. Only you (the owner) see it.</div>
        @endif
        <div class="tax-breakdown" data-show-if="invoice_requested">
            With an invoice: Net before tax {{ number_format($preview['net'], 2) }}
            · VAT {{ number_format($preview['vat'], 2) }}
            @if ($preview['table_tax'] > 0) · Table tax {{ number_format($preview['table_tax'], 2) }} @endif
            · <b>Total due {{ number_format($preview['with'], 2) }}</b> (extras included, at the saved discount)
        </div>
        @if ((float) $package->paid_amount > 0 && abs($preview['with'] - $current) >= 0.01 && !$package->invoice_requested)
            <div class="tax-warning" data-show-if="invoice_requested">The customer will owe {{ number_format($preview['with'] - $current, 2) }} more (taxes).</div>
        @endif
        @if ((float) $package->paid_amount > 0 && abs($current - $preview['without']) >= 0.01 && $package->invoice_requested)
            <div class="tax-warning" data-show-if="!invoice_requested">The customer will owe {{ number_format($current - $preview['without'], 2) }} less (no taxes).</div>
        @endif
    </div>
@endif
@endif

<div class="grid grid-cols-1 gap-5 mt-5 md:grid-cols-2">
    {{-- no Active / Inactive (user 2026-10-21): every package can be chosen --}}
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'value' => $package?->notes])
</div>
