{{-- The ONE "Not paid" list (2026-11-18, user), after the hourly / full day sections, before the subscriptions (ScheduleService::mergeNotPaid(); placed by Schedule/partials/sections): the subscriptions not
     paid + hourly reservations whose END TIME has passed (today included) + full day (day use) ones once their business day is
     over, still owing + packages started and still owing, not ended before the window (2026-11-18) (within Settings › General
     "Unpaid (past) Window", default 30 days). Member | Type | Unit | Date | Price | Status / Due | Pay;
     a member's rows together (any type) + "Member total due"; older hourly / full day ones = "N older unpaid → Outstanding report".
     Hidden when empty and on a past day. Rows: RenewalView kind not_paid. --}}
@if (empty($isPast) && ($notPaidRows->isNotEmpty() || $notPaidOlder > 0))
    @php($dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT)
    <section id="{{ $notPaidAnchor }}" class="today-section">
        <div class="today-section__head">
            <div class="today-section__title">Not paid <span class="today-section__count">{{ $notPaidRows->count() }}</span>
                <span class="text-xs font-normal text-slate-500">· Due {{ number_format($notPaidRows->sum('remaining'), 2) }}</span></div>
        </div>
        @if ($notPaidRows->isNotEmpty())
            <div class="table-wrap">
                <table class="data-table today-renewals">
                    <thead><tr><th>Member</th><th>Type</th><th>Unit</th><th>Date</th><th class="text-center">Price</th><th class="text-center">Status / Due</th><th class="text-right">Pay</th></tr></thead>
                    <tbody>
                        @foreach ($notPaidRows as $reservation)
                            @if ($reservation instanceof \Modules\PackageModule\app\Models\Package)
                                {{-- a package (2026-11-18): name | period | Due (total) | Unpaid / Partially paid + due | Pay = its Cash In --}}
                                <tr class="renewal-row">
                                    <td data-label="Member">
                                        <span class="font-medium">{{ $reservation->member?->name ?? '-' }}</span>
                                        @if ($reservation->member?->phone)<div class="text-xs text-slate-500">{{ $reservation->member->phone }}</div>@endif
                                    </td>
                                    <td data-label="Type">Package</td>
                                    <td data-label="Package"><a href="{{ route($area . '.packages.show', $reservation->id) }}" data-modal class="font-medium">{{ $reservation->name }}</a></td>
                                    <td data-label="Date"><span class="whitespace-nowrap">{{ $reservation->period }}</span></td>
                                    <td data-label="Due" class="renewal-row__price">{{ number_format($reservation->payableAmount(), 2) }}</td>
                                    <td data-label="Status / Due" class="renewal-row__status">
                                        @if ($reservation->paid_amount > 0)
                                            <span class="badge badge-status-warning">Partially paid</span>
                                            <div class="text-xs text-danger font-medium">Due {{ number_format($reservation->remaining, 2) }} of {{ number_format($reservation->payableAmount(), 2) }}</div>
                                        @else
                                            <span class="badge badge-status-danger">Unpaid</span>
                                            <div class="text-xs text-danger font-medium">Due {{ number_format($reservation->remaining, 2) }}</div>
                                        @endif
                                        @if (($reservation->older_due ?? 0) > 0)<div class="text-xs text-danger">Earlier unpaid of this member: {{ number_format($reservation->older_due, 2) }}</div>@endif
                                    </td>
                                    <td data-label="" class="renewal-row__action">
                                        @can('payments.create')
                                            <a href="{{ route($area . '.payments.create', ['payable' => 'package', 'payable_id' => $reservation->id]) }}" data-modal class="btn btn-pay btn-sm renewal-btn"><i data-lucide="wallet"></i> Pay</a>
                                        @endcan
                                    </td>
                                </tr>
                                @if (!empty($reservation->member_total))
                                    <tr class="renewal-row__total">
                                        <td colspan="7">Member total due: <b class="text-danger">{{ number_format($reservation->member_total, 2) }}</b> <span class="text-slate-500">({{ $reservation->member?->name }})</span></td>
                                    </tr>
                                @endif
                                @continue
                            @endif
                            @php($view = \Modules\ReservationModule\app\Services\RenewalView::make($reservation, 'not_paid', $date, $isToday))
                            <tr class="renewal-row">
                                <td data-label="Member">
                                    <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal class="font-medium">{{ $reservation->member?->name ?? '-' }}</a>
                                    @if ($reservation->member?->phone)<div class="text-xs text-slate-500">{{ $reservation->member->phone }}</div>@endif
                                </td>
                                <td data-label="Type">{{ $reservation->subscriptionType?->name ?? '-' }}</td>
                                <td data-label="Unit">
                                    {{ $reservation->unit?->name ?? '-' }}
                                    <div class="text-xs text-slate-500">{{ $reservation->space?->name }}</div>
                                    @include('contractmodule::Contract.partials.badge', ['reservation' => $reservation])
                                </td>
                                <td data-label="Date">
                                    {{-- a subscription: its period (dates); hourly / full day: the booking's date + time --}}
                                    @if ($reservation->isPeriod())
                                        <span class="whitespace-nowrap">{{ $view['date']?->format($dateFormat) }}</span>@if ($view['dateTo']) <span class="whitespace-nowrap">→ {{ $view['dateTo']->format($dateFormat) }}</span>@endif
                                    @else
                                        <span class="whitespace-nowrap">{{ $reservation->period }}</span>
                                    @endif
                                    <div class="text-xs whitespace-nowrap {{ $view['whenClass'] }}">{{ $view['when'] }}</div>
                                </td>
                                <td data-label="Price" class="renewal-row__price">{{ number_format($view['price'], 2) }}</td>
                                <td data-label="Status / Due" class="renewal-row__status">@include('reservationmodule::Reservation.partials.renewal-payment')</td>
                                <td data-label="" class="renewal-row__action">@include('reservationmodule::Reservation.partials.renewal-actions')@include('messagingmodule::Message.partials.button', ['messageContext' => 'owing', 'messageDate' => $date])</td>
                            </tr>
                            @if (!empty($reservation->member_total))
                                <tr class="renewal-row__total">
                                    <td colspan="7">Member total due: <b class="text-danger">{{ number_format($reservation->member_total, 2) }}</b> <span class="text-slate-500">({{ $reservation->member?->name }})</span></td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($notPaidOlder > 0)
            <div class="mt-3 text-sm text-slate-500">
                {{ $notPaidOlder }} older unpaid (hourly / full day / packages, ended more than {{ $unpaidDays }} days ago)
                @can('reports.outstanding')
                    → <a href="{{ route($area . '.reports.outstanding') }}" target="_blank" class="font-medium">Outstanding report</a>
                @else
                    (see the Outstanding report)
                @endcan
            </div>
        @endif
    </section>
@endif
