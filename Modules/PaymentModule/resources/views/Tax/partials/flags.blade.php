{{--
    The tax flags of a plan / income category (HasTaxFlags, docs/payments-plan.md §3), only while VAT is on (Settings › General):
    absent fields keep their stored values. $model = the plan / category (null on create), $showIf = optional data-show-if rule.
    @include('paymentmodule::Tax.partials.flags', ['model' => $plan, 'what' => 'reservations', 'invoiceDefault' => true])
    $invoiceDefault: + "Invoice by default" (pre-ticks "Invoice requested" on new rows)
    $inclusiveDefault (default true): "Price includes tax" on a new one (plans: false, user 2026-10-28)
--}}
@php($account = \Modules\AccountModule\app\Models\Account::current())
@if ($account?->vatEnabled())
    @php($rate = fn ($value) => \Modules\PlanModule\app\Models\Plan::taxRateText($value))
    <div @if (!empty($showIf)) data-show-if="{{ $showIf }}" @endif>
        <div class="form-section mt-6">Taxes</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'vat_exempt', 'label' => 'VAT', 'type' => 'select',
                'options' => [0 => 'VAT ' . $rate($account->vatRate()), 1 => 'Exempt'], 'value' => (int) ($model?->vat_exempt ?? 0)])
            @include('layoutmodule::partials.checkbox', ['name' => 'table_tax', 'label' => 'Table tax ' . $rate($account->tableTaxRate()),
                'checked' => $model?->table_tax ?? false])
            @include('layoutmodule::partials.checkbox', ['name' => 'price_includes_tax', 'label' => 'Price includes tax',
                'checked' => $model?->price_includes_tax ?? ($inclusiveDefault ?? true),
                'hint' => 'On: the amount is what the member pays. Off: the taxes are added to it.'])
            @if (!empty($invoiceDefault))
                @include('layoutmodule::partials.checkbox', ['name' => 'invoice_by_default', 'label' => 'Invoice by default',
                    'checked' => $model?->invoice_by_default ?? false,
                    'hint' => 'Ticks "Invoice requested" on new ' . $what . ' (it can be changed on each one). Taxes are calculated only with an invoice.'])
            @endif
        </div>
        <div class="{{ config('layoutmodule.form.hint') }}">
            Used for {{ $what }} created on or after the tax start date (Settings › General). Changing them never changes saved ones.
        </div>
    </div>
@endif
