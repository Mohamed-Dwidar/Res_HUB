{{-- The summary row: the day's count of each hourly / full day section, Not paid (Not renewed & Not paid) / Next N days of the
     period sections (2026-10-29; hidden on a past day), the new subscriptions starting
     on the day (New, only when > 0: scrolls to Starts today), the day's remaining (Due),
     the day's cancelled reservations (popup list) --}}
@php($firstPeriod = $sections->firstWhere('mode', 'period'))
@if ($sections->isNotEmpty())
    <div class="today-summary">
        @foreach ($sections->whereIn('mode', ['hourly', 'full_day']) as $section)
            <a href="#{{ $section['anchor'] }}" class="today-summary__item"><span>{{ $section['type']->name }}</span> <b>{{ $section['count'] }}</b></a>
        @endforeach
        {{-- the ONE Not paid list (every type, 2026-11-18): hidden on a past day --}}
        @if (empty($isPast))
            <a href="#{{ $notPaidAnchor }}" class="today-summary__item"><span>Not paid</span> <b class="{{ $notPaid ? 'text-danger' : '' }}">{{ $notPaid }}</b></a>
        @endif
        {{-- the subscriptions' chips in their section order (2026-11-18): New (Starts today) → Not renewed → Next N days --}}
        @if ($starts > 0)
            <a href="#{{ $startsAnchor }}" class="today-summary__item"><span>New</span> <b>{{ $starts }}</b></a>
        @endif
        @if ($firstPeriod && empty($firstPeriod['isPast']))
            <a href="#{{ $notRenewedAnchor ?? $firstPeriod['anchor'] }}" class="today-summary__item"><span>Not renewed</span> <b class="{{ $notRenewed ? 'text-danger' : '' }}">{{ $notRenewed }}</b></a>
            <a href="#{{ $nextAnchor ?? $firstPeriod['anchor'] }}" class="today-summary__item"><span>Next {{ $renewalDays }} days</span> <b>{{ $nextCount }}</b></a>
        @endif
        <span class="today-summary__item"><span>Due</span> <b class="{{ $due > 0 ? 'text-danger' : '' }}">{{ number_format($due, 2) }}</b></span>
        {{-- the day's cancelled reservations (ScheduleService::cancelledQuery()): opens their list --}}
        <a href="{{ route($area . '.schedule.cancelled', ['date' => $date->toDateString()]) }}" data-modal class="today-summary__item today-summary__item--danger"><span>Cancelled</span> <b>{{ $cancelled }}</b></a>
    </div>
@endif
