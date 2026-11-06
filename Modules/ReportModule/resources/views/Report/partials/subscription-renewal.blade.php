{{-- the Renewal Date cell (RenewalView): the renewal date; + "in N days" / "N days late" from the business date only where it is still
     open ($relative: Not renewed, Ending soon) --}}
{{ $view['date']?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) ?? '-' }}
@if ($relative && $view['when'])
    <div class="text-xs {{ $view['whenClass'] }}">{{ $view['when'] }}</div>
@endif
