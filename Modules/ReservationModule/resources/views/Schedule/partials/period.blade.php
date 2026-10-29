{{--
    A period section ($section, ScheduleService::periodSection()): "+ <type>"; under the title the Renews today and Not renewed
    periods counted per space (the top summary's style, user 2026-10-18); then Starts today (the new subscriptions starting on the
    day, 2026-10-19) and the renewals by the renewal date (the day after the last day): Renews today | Not renewed | Next N days
    (empty groups hidden). A renewed period shows its renewal (renewal_of) instead of Renew.
--}}
@php($type = $section['type'])
<section id="{{ $section['anchor'] }}" class="today-section">
    <div class="today-section__head">
        <div class="today-section__title">{{ $type->name }}</div>
        <div class="today-section__tools">
            <a href="{{ route($area . '.reservations.index', ['subscription_type_id' => $type->id]) }}" class="btn btn-secondary btn-sm">View all <i data-lucide="arrow-right"></i></a>
            {{-- a new one of this type (the form opens with the type fixed, for the selected day), named after the type --}}
            <a href="{{ route($area . '.reservations.create', ['subscription_type_id' => $type->id, 'start' => $date->toDateString()]) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> {{ $type->name }}</a>
        </div>
    </div>

    @php($groups = [
        ['title' => $isToday ? 'Starts today' : 'Starts this day', 'rows' => $section['startsToday'], 'kind' => 'new', 'id' => $section['startsAnchor']],
        ['title' => $isToday ? 'Renews today' : 'Renews this day', 'rows' => $section['renewsToday'], 'kind' => 'today'],
        ['title' => 'Not renewed', 'rows' => $section['notRenewed'], 'kind' => 'overdue'],
        ['title' => 'Next ' . $section['renewalDays'] . ' days', 'rows' => $section['next'], 'kind' => 'next'],
    ])

    {{-- Renews today / Not renewed: how many per space (the top summary's style; spaces in their order, only the ones with
         periods; a row per group, hidden when the group is empty) --}}
    @foreach (array_slice($groups, 1, 2) as $group)
        @continue($group['rows']->isEmpty())
        @php($bySpace = $group['rows']->groupBy('space_id')->sortBy(fn ($rows) => [(int) $rows->first()->space?->sort_order, (string) $rows->first()->space?->name]))
        <div class="today-summary today-summary--section">
            <span class="today-summary__label">{{ $group['title'] }}</span>
            @foreach ($bySpace as $rows)
                <span class="today-summary__item"><span>{{ $rows->first()->space?->name ?? '-' }}</span> <b class="{{ $group['kind'] === 'overdue' ? 'text-danger' : '' }}">{{ $rows->count() }}</b></span>
            @endforeach
        </div>
    @endforeach
    @if (!empty($section['isPast']))
        {{-- a past day (2026-10-29): Not renewed / Next days use today's payments, so they are not shown --}}
        <div class="today-empty">A past day: only Starts today and Renews today are shown.</div>
    @endif
    @if (collect($groups)->every(fn ($group) => $group['rows']->isEmpty()))
        <div class="today-empty">No renewals due.</div>
    @else
        <div class="table-wrap">
            <table class="data-table today-renewals">
                @foreach ($groups as $group)
                    @continue($group['rows']->isEmpty())
                    <tbody @isset($group['id']) id="{{ $group['id'] }}" @endisset>
                        <tr class="today-renewals__group"><td colspan="5">{{ $group['title'] }} ({{ $group['rows']->count() }})</td></tr>
                        @foreach ($group['rows'] as $reservation)
                            @include('reservationmodule::Schedule.partials.renewal-row', ['kind' => $group['kind']])
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</section>
