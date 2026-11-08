{{--
    The extras block of a package / reservation view: sales (revenue) and (packages) balance adjustments added to what it owes.
    @include('paymentmodule::Extra.partials.extras', ['extrable' => $package, 'kind' => 'package'])
    The account adds / edits / deletes them; employees add extras (sales) and see them (2026-10-21; permission extras.create).
    A reservation (2026-11-08): Amount = the line's net before tax (its taxes = the reservation's, shown once in the view's
    summary), Type = the category name only; a package: Amount + type badge (untaxed until Phase C); inside a package / not counted =
    "Add it on the package" / no Add button (ExtraService::extrableError()).
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $extras = $extrable->extrasList();
    $addError = app(\Modules\PaymentModule\app\Services\ExtraService::class)->extrableError($extrable);
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $taxCols = $kind === 'reservation';
    $lineNet = fn ($extra) => (float) ($extra->tax_net ?? $extra->amount);
@endphp

<div class="form-section mt-8 payments-block__head">
    <span>Extras ({{ $extras->count() }})</span>
    {{-- the account and employees (employees: extras only, no edit / delete) --}}
    @if (!$addError && Gate::allows('extras.create'))
        <a href="{{ route($area . '.extras.create', [$kind . '_id' => $extrable->id]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Extra</a>
    @endif
</div>

@if ($extras->isNotEmpty())
    <div class="table-wrap">
        <table class="data-table">
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
                        <td class="font-medium {{ $extra->amount < 0 ? 'text-danger' : '' }}">{{ number_format($taxCols ? $lineNet($extra) : $extra->amount, 2) }}</td>
                        <td class="whitespace-nowrap">{{ $extra->date?->format($dateFormat) }}</td>
                        <td>
                            {{ $extra->item }}
                            @if ($extra->notes)<div class="text-xs text-slate-500">{{ $extra->notes }}</div>@endif
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
                    <td>{{ number_format($taxCols ? $extras->sum($lineNet) : $extrable->extrasTotal(), 2) }}</td>
                    <td colspan="4">Total extras</td>
                    @if ($area === 'account')<td></td>@endif
                </tr>
            </tbody>
        </table>
    </div>
@else
    <div class="text-slate-500">{{ $addError && $kind === 'reservation' && !$extrable->canTakePayments() ? $addError : 'No extras.' }}</div>
@endif
