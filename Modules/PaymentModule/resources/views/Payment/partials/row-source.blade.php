{{--
    what is paid (opens its view in the popup): a reservation = (unit color) "Space › Unit" + its start under it; a package = its name;
    other income = the income category. $plain: the same text without its links (inside another link: the All / Cash Out list)
--}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@if ($payment->payable instanceof \Modules\ReservationModule\app\Models\Reservation)
    @if ($plain ?? false)
        @include('reservationmodule::Reservation.partials.row-place', ['reservation' => $payment->payable])
    @else
        <a href="{{ route($area . '.reservations.show', $payment->payable_id) }}" data-modal>@include('reservationmodule::Reservation.partials.row-place', ['reservation' => $payment->payable])</a>
    @endif
    {{-- $inline (the Cash In view): no start line, the view adds the whole period after it --}}
    @unless ($inline ?? false)
        <div class="text-xs text-slate-500 whitespace-nowrap">{{ $payment->payable->formatAt($payment->payable->start_at) }}</div>
    @endunless
@elseif ($payment->payable instanceof \Modules\PackageModule\app\Models\Package)
    @if ($plain ?? false)
        <i data-lucide="package" class="inline h-3 w-3"></i> {{ $payment->payable->name }}
    @else
        <a href="{{ route($area . '.packages.show', $payment->payable_id) }}" data-modal><i data-lucide="package" class="inline h-3 w-3"></i> {{ $payment->payable->name }}</a>
    @endif
@elseif ($payment->payable_type)
    {{-- deleted reservation / package --}}
    <span class="text-slate-500">#{{ $payment->payable_id }} (deleted)</span>
@else
    {{ $payment->incomeCategory?->name ?? 'Other income' }}
@endif
