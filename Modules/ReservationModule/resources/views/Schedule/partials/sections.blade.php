{{-- The Schedule page content (also answered alone by {area}.schedule.sections): night hint, shift strip, summary, the sections --}}
@if ($nightNow)
    <div class="today-night"><i data-lucide="moon"></i> Night shift · {{ $nightNow->format('d-m-Y h:i A') }}</div>
@endif

@include('reservationmodule::Schedule.partials.shift-strip')
@include('reservationmodule::Schedule.partials.summary')

{{-- order (2026-11-18, user): the hourly / full day sections (Meeting, Day Use …) → the ONE Not paid list (every type) → the
     subscriptions (period sections); no period section = Not paid at the end --}}
@php($notPaidShown = false)
@forelse ($sections as $section)
    @if ($section['mode'] === 'period' && !$notPaidShown)
        @include('reservationmodule::Schedule.partials.not-paid')
        @php($notPaidShown = true)
    @endif
    @include('reservationmodule::Schedule.partials.' . ['hourly' => 'hourly', 'full_day' => 'full-day', 'period' => 'period'][$section['mode']])
    @if ($loop->last && !$notPaidShown)
        @include('reservationmodule::Schedule.partials.not-paid')
    @endif
@empty
    <div class="today-empty">
        Nothing to show yet: turn on "Show on Home" for the subscription types that should appear here
        @if ($area === 'account')
            (<a href="{{ route('account.subscription-types.index') }}" class="font-medium">Subscription Types</a>).
        @endif
    </div>
@endforelse
