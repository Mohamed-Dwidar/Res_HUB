@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    <div class="text-lg font-medium">{{ $package->name }}</div>
@endsection

@section('modal-size', 'lg')

@section('actions')
    {{-- the combined invoice PDF (package + extras, 2026-11-08) --}}
    @include('paymentmodule::Payment.partials.invoice-button', ['payable' => $package, 'kind' => 'package'])
    <a href="{{ route($area . '.packages.edit', $package->id) }}" data-modal class="btn btn-secondary"><i
            data-lucide="pencil"></i> Edit</a>
    {{-- the customer package statement (PDF, new tab) --}}
    <a href="{{ route($area . '.packages.statement', $package->id) }}" target="_blank" class="btn btn-secondary"><i data-lucide="file-down"></i> Export PDF</a>
    @include('packagemodule::Package.partials.delete-form')
@endsection

@section('content')
    {{-- 2026-11-16 (user, layout only): the member + info lines on top, then ONE invoice-style list of the amounts
         (Payment/partials/invoice-summary = InvoicePdfService::summary(), the invoice PDF's order) --}}
    @php($hours = $package->reservationsHours())
    @php($counted = $package->reservations->filter(fn ($reservation) => $reservation->isCounted())->count())
    {{-- header: member + phone left, Due | Paid | Remaining right --}}
    <div class="flex flex-wrap items-center gap-4">
        <div>
            <div class="text-lg font-medium">{{ $package->member ? $package->member->name : '-' }}</div>
            <div class="mt-1 text-sm text-slate-500">{{ $package->member ? $package->member->phone : '-' }}</div>
        </div>
        @include('paymentmodule::Payment.partials.money-box', ['payable' => $package])
    </div>

    {{-- two columns on large screens (stacked on phones): left = info, right = the Amounts card (2026-11-16, user) --}}
    <div class="view-columns mt-6">
    <div>
    <div class="space-y-1 text-slate-600">
        <div><i data-lucide="calendar" class="inline h-4 w-4"></i> {{ $package->period }}</div>
        <div><i data-lucide="clock" class="inline h-4 w-4"></i>
            {{ $counted }} {{ $counted == 1 ? 'reservation' : 'reservations' }}
            · {{ rtrim(rtrim(number_format($hours['hours'], 2), '0'), '.') }} {{ $hours['hours'] == 1 ? 'hour' : 'hours' }}
            @if ($hours['open'])<span class="text-xs text-slate-500">(+ {{ $hours['open'] }} continuous, not counted)</span>@endif
        </div>
        @if ($package->isTaxed())
            {{-- the badge + its tax rule(s) only (the amounts are in the Amounts card) --}}
            <div>
                <span class="badge badge-status-info">Invoice requested</span>
                <span class="text-xs text-slate-500">{{ collect((array) $package->tax_groups)->pluck('label')->unique()->implode(' · ') }}</span>
            </div>
        @endif
        <div class="text-sm">Added by <b>{{ $package->creatable?->userable?->displayName() ?? $package->creatable?->email ?? '-' }}</b> | At {{ $package->created_at->format('d-m-Y h:i A') }}</div>
        @if ($package->notes)
            <div class="whitespace-pre-line text-sm">{{ $package->notes }}</div>
        @endif
    </div>
    </div>
    @include('paymentmodule::Payment.partials.invoice-summary', ['payable' => $package])
    </div>

    {{-- sales / adjustments added to what it owes, then its payments --}}
    @include('paymentmodule::Extra.partials.package-extras', ['package' => $package])
    {{-- its Due / Paid / Remaining are in Package Data above --}}
    @include('paymentmodule::Payment.partials.payable-payments', ['payable' => $package, 'kind' => 'package', 'summary' => false])

    <div class="form-section mt-8">Reservations ({{ $package->reservations->count() }})</div>
    @if ($package->reservations->isNotEmpty())
        {{-- scrolls when there are many reservations, the header stays visible (custom.css .table-wrap--scroll) --}}
        <div class="table-wrap table-wrap--scroll">
            {{-- fixed layout: the Space / Unit width is kept (custom.css .data-table--fixed, th.col-place) --}}
            <table class="data-table data-table--fixed">
                <thead>
                    <tr>
                        <th class="col-place">Space / Unit</th>
                        <th class="col-period">Period</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($package->reservations as $reservation)
                        <tr>
                            <td>
                                <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal
                                    class="font-medium">
                                    @include('reservationmodule::Reservation.partials.row-place')
                                </a>
                            </td>
                            <td>
                                <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal
                                    class="font-medium">{{ $reservation->period }}</a>
                            </td>

                            <td>
                                <span class="{{ $reservation->status?->badge_class ?? 'badge badge-status-slate' }}">{{ $reservation->status?->name ?? '-' }}</span>
                                @if ($reservation->status && !$reservation->status->is_counted)
                                    <div class="mt-1 text-xs text-slate-500">not counted</div>
                                @endif
                            </td>
                            <td>{{ number_format($reservation->net_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-slate-500">No reservations in this package yet.</div>
    @endif

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.packages.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to
                Packages</a>
        </div>
    @endunless
@endsection
