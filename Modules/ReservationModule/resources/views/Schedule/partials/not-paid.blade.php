{{-- The ONE "Not paid" list (2026-11-18, user), after the hourly / full day sections, before the subscriptions (ScheduleService::mergeNotPaid(); placed by Schedule/partials/sections): the subscriptions not
     paid + hourly reservations whose END TIME has passed (today included) + full day (day use) ones once their business day is
     over, still owing (within Settings › General "Unpaid (past) Window", default 30 days). Member | Type | Unit | Date | Price | Status / Due | Pay;
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
                {{ $notPaidOlder }} older unpaid (hourly / full day, more than {{ $unpaidDays }} days ago)
                @can('reports.outstanding')
                    → <a href="{{ route($area . '.reports.outstanding') }}" target="_blank" class="font-medium">Outstanding report</a>
                @else
                    (see the Outstanding report)
                @endcan
            </div>
        @endif
    </section>
@endif
