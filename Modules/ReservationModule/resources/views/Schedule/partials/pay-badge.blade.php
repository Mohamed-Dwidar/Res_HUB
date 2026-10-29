{{-- A reservation's payment on the Schedule page: Paid / Unpaid / Partial / In package (link to the package) / Credit ... --}}
@if ($reservation->package_id)
    <a href="{{ route($area . '.packages.show', $reservation->package_id) }}" data-modal class="badge badge-status-primary"><i data-lucide="package" class="inline h-3 w-3"></i> In package</a>
@elseif (in_array($reservation->payment_status, ['unpaid', 'partial'], true))
    <span class="badge {{ $reservation->payment_status_badge_class }}">{{ $reservation->payment_status === 'partial' ? 'Partial' : 'Unpaid' }}</span>
@else
    @include('paymentmodule::Payment.partials.payment-badge', ['payable' => $reservation])
@endif
