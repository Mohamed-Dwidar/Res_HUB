{{--
    The extras block of a package / reservation view: sales (revenue) and (packages) balance adjustments added to what it owes.
    @include('paymentmodule::Extra.partials.extras', ['extrable' => $package, 'kind' => 'package'])
    The account adds / edits / deletes them; employees add extras (sales) and see them (2026-10-21; permission extras.create).
    A reservation (2026-11-08): Amount = the line's net before tax (its taxes = the reservation's, shown once in the view's
    summary), Type = the category name only; a package: Amount (net before tax; taxed: "incl. taxes" under it, Phase C) + type
    badge; inside a package / not counted =
    "Add it on the package" / no Add button (ExtraService::extrableError()).
    2026-11-16 (user): two blocks — Extras (sales: taxed with the reservations while the invoice is requested) and, after it,
    "Balance carried / adjustments" (adjustments + untaxed categories such as Rounding: added after tax,
    ExtraService::afterTax()); display only, no calculation change.
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $all = $extrable->extrasList();
    $extraService = app(\Modules\PaymentModule\app\Services\ExtraService::class);
    [$adjustments, $extras] = $all->partition(fn ($extra) => $extraService->afterTax($extra));
    $taxed = $extrable->isTaxed();
    $addError = app(\Modules\PaymentModule\app\Services\ExtraService::class)->extrableError($extrable);
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $taxCols = $kind === 'reservation';
    $lineNet = fn ($extra) => (float) ($extra->tax_net ?? $extra->amount);
@endphp

<div class="form-section mt-8 payments-block__head">
    <span>Extras ({{ $extras->count() }})
        <span class="text-xs font-normal text-slate-500">· sales, {{ $taxed ? 'taxed with the ' . ($kind === 'package' ? 'reservations' : 'reservation') . ' (invoice requested)' : 'no invoice: no taxes' }}</span>
    </span>
    {{-- the account and employees (employees: extras only, no edit / delete) --}}
    {{-- $buttons false (the package view, 2026-11-18): "Extra" is in the view header --}}
    @if (($buttons ?? true) && !$addError && Gate::allows('extras.create'))
        <a href="{{ route($area . '.extras.create', [$kind . '_id' => $extrable->id]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Extra</a>
    @endif
</div>

@if ($extras->isNotEmpty())
    <div class="table-wrap table-wrap--tips">
        <table class="data-table data-table--fit">
            <thead>
                {{-- user 2026-10-21: Amount, Date, Item, Qty × Price, Type --}}
                <tr>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Qty × Price</th>
                    <th>Type</th>
                    @if ($area === 'account')<th></th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($extras as $extra)
                    <tr>
                        <td class="font-medium {{ $extra->amount < 0 ? 'text-danger' : '' }}">{{ number_format($lineNet($extra), 2) }}
                            {{-- a package's taxed extra (Phase C): its total incl. taxes under the net --}}
                            @if (!$taxCols && $extra->tax_total !== null && (float) $extra->tax_total != (float) $extra->tax_net)
                                <div class="text-xs font-normal text-slate-500">incl. taxes {{ number_format($extra->tax_total, 2) }}</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $extra->date?->format($dateFormat) }}</td>
                        <td>
                            {{ $extra->item }}
                            @include('layoutmodule::partials.note-tip', ['note' => $extra->notes])
                        </td>
                        <td class="whitespace-nowrap">{{ rtrim(rtrim(number_format($extra->quantity, 2), '0'), '.') }} × {{ number_format($extra->unit_price, 2) }}</td>
                        {{-- one line: the type badge · its income category (extras) --}}
                        <td class="whitespace-nowrap">
                            @if ($taxCols)
                                {{ $extra->incomeCategory?->name ?? '-' }}
                            @else
                                <span class="badge {{ $extra->type_badge_class }}">{{ $extra->type_label }}</span>
                                @if ($extra->type === 'extra' && $extra->incomeCategory)<span class="text-xs text-slate-500">· {{ $extra->incomeCategory->name }}</span>@endif
                            @endif
                        </td>
                        @if ($area === 'account')
                            {{-- edit + delete on one line (.payments-block__icons, like the Cash In list) --}}
                            <td class="whitespace-nowrap">
                                <span class="payments-block__icons">
                                    <a href="{{ route('account.extras.edit', $extra->id) }}" data-modal title="Edit"><i data-lucide="pencil" class="h-4 w-4"></i></a>
                                    @include('paymentmodule::Extra.partials.delete-form')
                                </span>
                            </td>
                        @endif
                    </tr>
                @endforeach
                <tr class="font-medium">
                    <td>{{ number_format($extras->sum($lineNet), 2) }}
                        @php($salesDue = $extras->sum(fn ($extra) => (float) ($extra->tax_total ?? $extra->amount)))
                        @if (!$taxCols && abs($salesDue - $extras->sum($lineNet)) >= 0.01)
                            <div class="text-xs font-normal text-slate-500">incl. taxes {{ number_format($salesDue, 2) }}</div>
                        @endif
                    </td>
                    <td colspan="4">Total extras (sales)</td>
                    @if ($area === 'account')<td></td>@endif
                </tr>
            </tbody>
        </table>
    </div>
@else
    <div class="text-slate-500">{{ $addError && $kind === 'reservation' && !$extrable->canTakePayments() ? $addError : 'No extras.' }}</div>
@endif

{{-- adjustments / Rounding: added AFTER tax (never taxed) --}}
@if ($adjustments->isNotEmpty())
    <div class="form-section mt-8">Balance carried / adjustments ({{ $adjustments->count() }})
        <span class="text-xs font-normal text-slate-500">· added after tax, never taxed</span>
    </div>
    <div class="table-wrap table-wrap--tips">
        <table class="data-table data-table--fit">
            <thead>
                <tr>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Type</th>
                    @if ($area === 'account')<th></th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($adjustments as $extra)
                    <tr>
                        <td class="font-medium {{ $extra->amount < 0 ? 'text-danger' : '' }}">{{ number_format($extra->amount, 2) }}</td>
                        <td class="whitespace-nowrap">{{ $extra->date?->format($dateFormat) }}</td>
                        <td>
                            {{ $extra->item }}
                            @include('layoutmodule::partials.note-tip', ['note' => $extra->notes])
                        </td>
                        <td class="whitespace-nowrap">{{ $extra->type === 'extra' ? ($extra->incomeCategory?->name ?? '-') : $extra->type_label }}</td>
                        @if ($area === 'account')
                            <td class="whitespace-nowrap">
                                <span class="payments-block__icons">
                                    <a href="{{ route('account.extras.edit', $extra->id) }}" data-modal title="Edit"><i data-lucide="pencil" class="h-4 w-4"></i></a>
                                    @include('paymentmodule::Extra.partials.delete-form')
                                </span>
                            </td>
                        @endif
                    </tr>
                @endforeach
                @if ($adjustments->count() > 1)
                    <tr class="font-medium">
                        <td>{{ number_format($adjustments->sum('amount'), 2) }}</td>
                        <td colspan="3">Total adjustments</td>
                        @if ($area === 'account')<td></td>@endif
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
@endif
