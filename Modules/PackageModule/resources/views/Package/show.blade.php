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
    {{-- left: the member name + phone (smaller); right: Due | Paid | Remaining in one block, each in its color (2026-10-21) --}}
    @php($due = $package->payableAmount())
    @php($extras = $package->extrasTotal())
    @php($remaining = (float) $package->remaining)
    <div class="flex flex-wrap items-center gap-4">
        <div>
            <div class="text-lg font-medium">
                {{ $package->member ? $package->member->name : '-' }}
            </div>
            <div class="mt-1 text-sm text-slate-500">
                {{ $package->member ? $package->member->phone : '-' }}
            </div>
        </div>
        <div class="money-box ml-auto">
            <div class="money-box__item money-box__item--due" title="Net + extras">
                <span>Due</span><b>{{ number_format($due, 2) }}</b>
            </div>
            <div class="money-box__item money-box__item--paid">
                <span>Paid</span><b>{{ number_format($package->paid_amount, 2) }}</b>
            </div>
            <div class="money-box__item {{ $remaining > 0 ? 'money-box__item--owed' : ($remaining < 0 ? 'money-box__item--credit' : 'money-box__item--paid') }}">
                <span>{{ $remaining < 0 ? 'Credit' : 'Remaining' }}</span><b>{{ number_format(abs($remaining), 2) }}</b>
            </div>
        </div>
    </div>

    <div class="form-section mt-6">Package Data</div>
    <dl class="detail-list">
        <div>
            <dt>Period</dt>
            <dd>{{ $package->period }}</dd>
        </div>
        <div>
            <dt>Price <span class="text-slate-400">(reservations net)</span></dt>
            <dd class="font-medium">{{ number_format($package->amount, 2) }}</dd>
        </div>
        <div>
            <dt>Discount</dt>
            <dd>{{ rtrim(rtrim(number_format($package->discount_percentage, 2), '0'), '.') }}%</dd>
        </div>
        <div>
            <dt>Net</dt>
            <dd class="font-medium">{{ number_format($package->after_discount, 2) }}</dd>
        </div>
        {{-- every amount (2026-10-21): + extras = due, paid, remaining (or credit) + the payment status --}}
        <div>
            <dt>Extras</dt>
            <dd>{{ number_format($extras, 2) }}</dd>
        </div>
        <div>
            <dt>Due <span class="text-slate-400">(net + extras)</span></dt>
            <dd class="font-bold text-primary">{{ number_format($due, 2) }}</dd>
        </div>
        <div>
            <dt>Paid</dt>
            <dd class="font-bold text-success">{{ number_format($package->paid_amount, 2) }}</dd>
        </div>
        <div>
            <dt>{{ $remaining < 0 ? 'Credit' : 'Remaining' }}</dt>
            <dd class="font-bold {{ $remaining > 0 ? 'text-danger' : ($remaining < 0 ? 'text-info' : '') }}">
                {{ number_format(abs($remaining), 2) }}
                <span class="badge {{ $package->payment_status_badge_class }} ml-1">{{ $package->payment_status_label }}</span>
            </dd>
        </div>
        @php($hours = $package->reservationsHours())
        <div>
            <dt>Total Hours</dt>
            <dd>
                {{ rtrim(rtrim(number_format($hours['hours'], 2), '0'), '.') }}
                {{ $hours['hours'] == 1 ? 'hour' : 'hours' }}
                @if ($hours['open'])
                    <span class="text-xs text-slate-500">(+ {{ $hours['open'] }} continuous reservation(s), not
                        counted)</span>
                @endif
            </dd>
        </div>
        <div>
            <dt>Added At</dt>
            <dd>{{ $package->created_at->format('Y-m-d H:i') }}</dd>
        </div>
        <div class="wide">
            <dt>Notes</dt>
            <dd>{{ $package->notes ?: '-' }}</dd>
        </div>
    </dl>

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
