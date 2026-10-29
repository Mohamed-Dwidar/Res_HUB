{{--
    A period section ($section, ScheduleService::periodSection() / periodGroups(), user 2026-10-29): "+ <type>"; "Not paid by
    space:" chips (the Not renewed & Not paid rows per space; a chip filters the table to that space, again = all); then the groups
    Starts today | Renews today | Not renewed & Not paid | Next N days (empty groups hidden), one table:
    Member | Unit | Renewal date | Price | Payment | Action (Schedule/partials/renewal-row, RenewalView).
--}}
@php($type = $section['type'])
<section id="{{ $section['anchor'] }}" class="today-section" data-space-filter-scope>
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
        ['title' => 'Not renewed & Not paid', 'rows' => $section['notPaid'], 'kind' => 'owing', 'id' => $section['notPaidAnchor']],
        ['title' => 'Next ' . $section['renewalDays'] . ' days', 'rows' => $section['next'], 'kind' => 'next', 'id' => $section['nextAnchor']],
    ])

    {{-- Not renewed & Not paid per space (spaces in their order); a chip shows only that space's rows --}}
    @if ($section['notPaid']->isNotEmpty())
        @php($bySpace = $section['notPaid']->groupBy('space_id')->sortBy(fn ($rows) => [(int) $rows->first()->space?->sort_order, (string) $rows->first()->space?->name]))
        <div class="today-summary today-summary--section">
            <span class="today-summary__label">Not paid by space:</span>
            @foreach ($bySpace as $spaceId => $rows)
                <button type="button" class="today-summary__item" data-space-filter="{{ $spaceId }}" title="Show only {{ $rows->first()->space?->name }} (click again for all)"><span>{{ $rows->first()->space?->name ?? '-' }}</span> <b class="text-danger">{{ $rows->count() }}</b></button>
            @endforeach
        </div>
    @endif
    @if (!empty($section['isPast']))
        {{-- a past day (2026-10-29): the two lists use today's payments, so they are not shown --}}
        <div class="today-empty">A past day: only Starts today and Renews today are shown.</div>
    @endif
    @if (collect($groups)->every(fn ($group) => $group['rows']->isEmpty()))
        <div class="today-empty">No renewals due.</div>
    @else
        <div class="table-wrap">
            <table class="data-table today-renewals">
                <colgroup><col class="renewal-col--member"><col class="renewal-col--unit"><col class="renewal-col--date"><col class="renewal-col--price"><col class="renewal-col--payment"><col class="renewal-col--action"></colgroup>
                @foreach ($groups as $group)
                    @continue($group['rows']->isEmpty())
                    <tbody @isset($group['id']) id="{{ $group['id'] }}" @endisset>
                        <tr class="today-renewals__group"><td colspan="6">{{ $group['title'] }} ({{ $group['rows']->count() }})</td></tr>
                        {{-- each group has its own header (2026-10-29): Not renewed & Not paid shows the period in "Date" --}}
                        <tr class="today-renewals__head"><th>Member</th><th>Unit</th><th>{{ $group['kind'] === 'owing' ? 'Date' : 'Renewal date' }}</th><th class="text-center">Price</th>@if ($group['kind'] === 'owing')<th class="text-center">Status</th>@else<th>Payment</th>@endif<th class="text-center">Action</th></tr>
                        @foreach ($group['rows'] as $reservation)
                            @include('reservationmodule::Schedule.partials.renewal-row', ['kind' => $group['kind']])
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</section>
