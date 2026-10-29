{{--
    The line under the reservations list filters with the "Renewal" filter (ReservationActions::renewalSummary(), 2026-10-29):
    the dates, the count and — Not renewed & Not paid: what is owed + the paid periods not renewed; Upcoming: not renewed yet
    (+ their price), renewed and owing (+ the debt), renewed & paid.
--}}
@php($dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT)
<div class="renewal-summary">
    <b>{{ \Modules\ReservationModule\app\Models\Reservation::RENEWAL_FILTERS[$status] }}</b>
    @if ($status === 'not_paid')
        · due since {{ $from->year > 2000 ? $from->format($dateFormat) . ' → ' : 'up to ' }}{{ $to->format($dateFormat) }}
    @else
        · renewal dates {{ $from->format($dateFormat) }} → {{ $to->format($dateFormat) }}
    @endif
    · <b>{{ number_format($totals->periods) }}</b> {{ Str::plural('period', (int) $totals->periods) }}
    @if ($status === 'not_paid')
        · Due <b class="text-danger">{{ number_format($totals->own_due, 2) }}</b>
        @if ($totals->paid_not_renewed > 0)
            · paid, not renewed <b>{{ number_format($totals->paid_not_renewed) }}</b>
        @endif
    @elseif ($status === 'upcoming')
        · not renewed yet <b>{{ number_format($totals->not_created) }}</b> worth {{ number_format($totals->not_created_amount, 2) }}
        · renewed, not paid <b>{{ number_format($totals->renewed_owing) }}</b> due <span class="text-danger">{{ number_format($totals->renewal_due, 2) }}</span>
        · renewed &amp; paid <b>{{ number_format($totals->periods - $totals->not_created - $totals->renewed_owing) }}</b>
    @endif
</div>
