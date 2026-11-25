{{--
    The list Status column: the reservation status badge, then the payment status badge under it
    (Paid / Unpaid / Partial / In package / Nothing due / Credit). A not counted reservation (cancelled ...): its status only.
    In a package: the "In package" badge opens the package view (popup).
--}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<div><span class="{{ $reservation->status?->badge_class ?? 'badge badge-status-slate' }}">{{ $reservation->status?->name ?? '-' }}</span></div>
@if ($reservation->isCounted())
    {{-- Paid = a solid green badge, Contract (below) = a solid blue one (2026-11-25, user; custom.css .row-badge) --}}
    <div @class(['mt-1', 'row-badge row-badge--paid' => $reservation->payment_status === 'paid'])>
        @if ($reservation->package_id && $reservation->package)
            <a href="{{ route($area . '.packages.show', $reservation->package_id) }}" data-modal class="badge {{ $reservation->payment_status_badge_class }}" title="Open the package {{ $reservation->package->name }}"><i data-lucide="package" class="inline h-3 w-3"></i> {{ $reservation->payment_status_label }}</a>
        @else
            @include('paymentmodule::Payment.partials.payment-badge', ['payable' => $reservation])
        @endif
    </div>
@endif
{{-- a subscription's contract badge (ContractModule, 2026-11-25) --}}
<div class="row-badge row-badge--contract">@include('contractmodule::Contract.partials.badge')</div>
