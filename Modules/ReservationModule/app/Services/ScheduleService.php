<?php

namespace Modules\ReservationModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\AccountModule\app\Models\Account;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationStatus;
use Modules\SpaceModule\app\Models\Space;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UnitModule\app\Models\Unit;

/**
 * The Schedule page (account + employees, same content): built ONLY from the account's own data — one section per subscription
 * type with Show on Home (home_order, then name), its layout by booking_mode; nothing is named in code (multi account).
 *
 * The business day (Settings › General, Account::businessDay()): D = [D cutoff:00, D+1 cutoff:00), cutoff = timeline "to" − 24
 * (Pivot: to 26 → 02:00). The timeline shows D from:00 → D to:00 (to > 24 = the next morning).
 * - hourly: the reservations STARTING in the business day are D's (counted, Due); the ones started before it (the previous
 *   evening) show for information only ("from yesterday", not counted again); timeline rows = the type's active units
 *   (grouped by space when the type has more than one space); an item ended before "from" = the Earlier column, one starting
 *   after "to" = the Later column; the rest in the grid (15 minute slots, clipped at the edges), in lanes when they overlap
 * - full_day: by their DATE (start_at day = D: the form gives a full day reservation the business date at night)
 * - period (no running counts per space, user 2026-10-15; the view shows the Renews today / Not renewed totals): Starts today
 *   (new subscriptions starting on D: no renewal_of, not left; 2026-10-19) | renewals by the RENEWAL DATE (the day after the last
 *   day, user 2026-10-15): Renews today (= D) | Not renewed | Next N days (D+1 … D+N). Payment is due at the START of a period
 *   (2026-10-19): renewed = its renewal exists AND is paid (remaining <= 0); a renewal still owing = "Renewal created · Due" + Pay
 *   (Renews today / Next). Not renewed = (a) ended before D, no renewal, not left: Renew / Mark left; (b) the chain's CURRENT
 *   period (started on / before D, no renewal or one starting after D) still owing: "due since <its start>" + Pay — the new
 *   subscribers' unpaid first periods too; a chain is shown once (its old period hidden by (b); Starts today keeps its own)
 * Only counted reservations (not cancelled like ones).
 */
class ScheduleService
{
    // the timeline slot (minutes) — custom.css .tl-from-N / .tl-span-N (N = 1 … 120: 30 hours)
    const SLOT_MINUTES = 15;
    // the most lanes of one unit row (custom.css .tl-lane-N / .tl-lanes-N); more overlaps share the last lane
    const MAX_LANES = 12;

    public function page(int $accountId, Account $account, Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        $today = $account->businessDate();
        [$from, $to] = $account->todayHours();
        [$dayStart, $dayEnd] = $account->businessDay($date);

        $context = [
            'accountId' => $accountId,
            'date' => $date,
            'dayStart' => $dayStart,
            'dayEnd' => $dayEnd,
            // the timeline window
            'windowStart' => $date->copy()->addHours($from),
            'windowEnd' => $date->copy()->addHours($to),
            'isToday' => $date->eq($today),
            // a past day (before the business date): no Next N days / Not renewed (today's payments would mislead), 2026-10-29
            'isPast' => $date->lt($today),
            'renewalDays' => $account->renewalDays(),
        ];

        // read once for the whole page (2026-10-18, 85 → ~16 queries): the account's types, units (+ colors), spaces, statuses;
        // the sections ask one query each, then their rows get their relations in one go (hydrate())
        $allTypes = SubscriptionType::where('account_id', $accountId)->orderBy('home_order')->orderBy('name')->get();
        $types = $allTypes->where('show_home', true)->values();
        $context['types'] = $allTypes->keyBy('id');
        $context['account'] = $account;
        $context['units'] = $this->unitsWithColor($accountId);
        $context['spaces'] = Space::withTrashed()->where('account_id', $accountId)->get()->keyBy('id');
        $sections = $types->map(fn ($type) => match ($type->booking_mode) {
            'full_day' => $this->fullDaySection($type, $context),
            'period' => $this->periodSection($type, $context),
            default => $this->hourlySection($type, $context),
        });
        $this->hydrate($sections, $context);
        // the period groups need the renewals (renewedBy) and the members, loaded by hydrate()
        $sections = $sections->map(fn ($section) => $section['mode'] === 'period' ? $this->periodGroups($section, $context) : $section);

        return [
            'date' => $date,
            'today' => $today,
            'isToday' => $context['isToday'],
            // at night (before the cutoff) the real date differs from the business date: the header hint
            'nightNow' => $context['isToday'] && !$today->isSameDay(now()) ? now() : null,
            'hours' => [$from, $to],
            'windowStart' => $context['windowStart'],
            'sections' => $sections,
            // the summary row: hourly / full day counts, renewals that need action, the remaining of the day
            'renewals' => $sections->where('mode', 'period')->sum('needAction'),
            // the new subscriptions starting on the day (the summary "New", hidden when 0) + the first section that has some
            'starts' => $sections->where('mode', 'period')->sum(fn ($section) => $section['startsToday']->count()),
            'startsAnchor' => $sections->where('mode', 'period')->first(fn ($section) => $section['startsToday']->isNotEmpty())['startsAnchor'] ?? null,
            'hasPeriod' => $sections->contains('mode', 'period'),
            'due' => round($sections->whereIn('mode', ['hourly', 'full_day'])->sum('due'), 2),
            // the day's cancelled reservations (the summary "Cancelled", opens their list)
            // (the same rule as cancelledQuery(), counted from the sections' own queries)
            'cancelled' => $sections->sum('cancelled'),
            // + New: every type of the account (pick a type first), with its start for the selected day
            'newTypes' => $allTypes->map(fn ($type) => ['type' => $type, 'start' => $this->newStart($type, $context, $from)]),
        ];
    }

    /**
     * The day's cancelled reservations (a not counted status) of the page's types (Show on Home), by the sections' day rule:
     * hourly = starting in the business day [D cutoff, D+1 cutoff), full day / period = starting on the date D.
     * The summary "Cancelled" count + its popup (route {area}.schedule.cancelled).
     */
    public function cancelledQuery(int $accountId, Account $account, Carbon $date, ?Collection $types = null)
    {
        $date = $date->copy()->startOfDay();
        [$dayStart, $dayEnd] = $account->businessDay($date);
        // the page's types (Show on Home): given by page(), else read
        $types ??= SubscriptionType::where('account_id', $accountId)->where('show_home', true)->get(['id', 'booking_mode']);
        $hourly = $types->where('booking_mode', 'hourly')->pluck('id')->all() ?: [0];
        $others = $types->where('booking_mode', '!=', 'hourly')->pluck('id')->all() ?: [0];

        return Reservation::where('account_id', $accountId)
            ->whereIn('reservation_status_id', ReservationStatus::notCountedIds() ?: [0])
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('subscription_type_id', $hourly)->where('start_at', '>=', $dayStart)->where('start_at', '<', $dayEnd))
                ->orWhere(fn ($query) => $query->whereIn('subscription_type_id', $others)->where('start_at', '>=', $date)->where('start_at', '<', $date->copy()->addDay())));
    }

    public function cancelled(int $accountId, Account $account, Carbon $date): Collection
    {
        return $this->cancelledQuery($accountId, $account, $date)
            ->with('member', 'space', 'unit.color', 'subscriptionType', 'status')
            ->orderBy('start_at')->orderBy('id')->get();
    }

    // the start of a new reservation of the type for the day (+ New, + <type>): hourly on today = now, on another day = the
    // timeline start; full day / period = the date
    public function newStart(SubscriptionType $type, array $context, int $from): string
    {
        if ($type->isHourly()) {
            return $context['isToday'] ? now()->format('Y-m-d H:i') : $context['date']->copy()->addHours($from)->format('Y-m-d H:i');
        }
        return $context['date']->format('Y-m-d');
    }

    // the type's counted reservations for the page (their relations: hydrate()); $counted false = every status (the section
    // splits the cancelled like ones off for the summary "Cancelled")
    private function reservations(SubscriptionType $type, int $accountId, bool $counted = true)
    {
        return Reservation::where('account_id', $accountId)->where('subscription_type_id', $type->id)->when($counted, fn ($query) => $query->counted());
    }

    // not counted (cancelled like): the summary "Cancelled" (the same rule as cancelledQuery(), split from the sections' rows)
    private function notCounted(Reservation $reservation): bool
    {
        return in_array((int) $reservation->reservation_status_id, ReservationStatus::notCountedIds(), true);
    }

    // the account's units (deleted too) with their color, in ONE query (colors joined; the color relation set from it)
    private function unitsWithColor(int $accountId): Collection
    {
        return Unit::withTrashed()->where('units.account_id', $accountId)
            ->leftJoin('colors', 'colors.id', '=', 'units.color_id')
            ->select('units.*', 'colors.name as joined_color_name', 'colors.value as joined_color_value')
            ->get()
            ->each(function ($unit) {
                $color = $unit->color_id && $unit->joined_color_value !== null
                    ? (new \Modules\UnitModule\app\Models\Color())->forceFill(['id' => $unit->color_id, 'name' => $unit->joined_color_name, 'value' => $unit->joined_color_value])
                    : null;
                $unit->setRelation('color', $color);
                unset($unit->joined_color_name, $unit->joined_color_value);
            })
            ->keyBy('id');
    }

    /**
     * The relations of every row of the page in one go: unit (+ color) / space / status / type from the lists read once by page(),
     * member, package, plan, creator (+ its user type) and the renewal of the periods by one query each.
     */
    private function hydrate(Collection $sections, array $context): void
    {
        $rows = new \Illuminate\Database\Eloquent\Collection();
        foreach ($sections as $section) {
            $lists = match ($section['mode']) {
                'hourly' => [$section['list']],
                'full_day' => [$section['rows']],
                default => [$section['periods']],
            };
            foreach ($lists as $list) {
                foreach ($list as $reservation) {
                    $rows->push($reservation);
                }
            }
        }
        $statuses = ReservationStatus::allStatuses();
        foreach ($rows as $reservation) {
            $reservation->setRelation('unit', $context['units'][$reservation->unit_id] ?? null);
            $reservation->setRelation('space', $context['spaces'][$reservation->space_id] ?? null);
            $reservation->setRelation('status', $statuses[$reservation->reservation_status_id] ?? null);
            $reservation->setRelation('subscriptionType', $context['types'][$reservation->subscription_type_id] ?? null);
        }
        $rows->load('member', 'package', 'plan', 'creatable');
        // the creators' user types: the account's own login = the page's account (no query), the employees in one query
        $users = $rows->pluck('creatable')->filter()->unique('id');
        $accountType = (new Account())->getMorphClass();
        $others = new \Illuminate\Database\Eloquent\Collection();
        foreach ($users as $user) {
            if ($user->userable_type === $accountType && (int) $user->userable_id === (int) $context['account']->id) {
                $user->setRelation('userable', $context['account']);
            } else {
                $others->push($user);
            }
        }
        $others->load('userable');
        // the renewal of the periods only (the renewal rows, the Renew check)
        $periods = new \Illuminate\Database\Eloquent\Collection($rows->filter(fn ($reservation) => $reservation->isPeriod())->values()->all());
        $periods->load('renewedBy');
        // a renewal is shown as a row once its renewal date has come (periodGroups(), 2026-10-29): the same relations as the rows
        $renewals = new \Illuminate\Database\Eloquent\Collection($periods->pluck('renewedBy')->filter()->values()->all());
        foreach ($renewals as $renewal) {
            $renewal->setRelation('unit', $context['units'][$renewal->unit_id] ?? null);
            $renewal->setRelation('space', $context['spaces'][$renewal->space_id] ?? null);
            $renewal->setRelation('status', $statuses[$renewal->reservation_status_id] ?? null);
            $renewal->setRelation('subscriptionType', $context['types'][$renewal->subscription_type_id] ?? null);
        }
        $renewals->load('member', 'package', 'plan');
    }

    // remaining owed (> 0) of reservations paid on their own (not inside a package)
    private function dueOf(Collection $reservations): float
    {
        return round($reservations->filter(fn ($reservation) => $reservation->canTakePayments() && $reservation->remaining > 0)->sum('remaining'), 2);
    }

    private function hourlySection(SubscriptionType $type, array $context): array
    {
        [$dayStart, $dayEnd, $windowStart, $windowEnd] = [$context['dayStart'], $context['dayEnd'], $context['windowStart'], $context['windowEnd']];
        $midnight = $context['date'];
        // starting in the business day (every status: the cancelled ones are counted for the summary), or (counted) started before
        // it and still running after midnight of D (the previous evening) — a UNION: each part uses its index (72 → 2 ms)
        $starting = $this->reservations($type, $context['accountId'], false)
            ->where('start_at', '>=', $dayStart)->where('start_at', '<', $dayEnd->max($windowEnd));
        $running = $this->reservations($type, $context['accountId'])
            ->where('start_at', '<', $dayStart)->where(fn ($query) => $query->where('end_at', '>', $midnight)->orWhereNull('end_at'));
        $all = $starting->union($running)->get();
        $cancelled = $all->filter(fn ($reservation) => $this->notCounted($reservation) && $reservation->start_at->lt($dayEnd))->count();
        $reservations = $all->reject(fn ($reservation) => $this->notCounted($reservation))
            ->sortBy(fn ($reservation) => [$reservation->start_at->getTimestamp(), $reservation->id])->values()
            // an end before the start (old same-day rows) = it ends at its start
            ->each(function ($reservation) use ($dayStart) {
                $reservation->today_own = $reservation->start_at->gte($dayStart);
            });
        $own = $reservations->where('today_own', true);

        $slots = intdiv((int) $windowStart->diffInMinutes($windowEnd), self::SLOT_MINUTES);
        // the type's active units (of a not deleted space), in their order (Unit::scopeOrdered: sort_order, name) — from the
        // page's list (a unit gets its type from its space's types, so the type's spaces need no lookup)
        $order = fn ($model) => [(int) $model->sort_order, mb_strtolower((string) $model->name)];
        $units = $context['units']->filter(fn ($unit) => !$unit->trashed() && $unit->is_active && (int) $unit->subscription_type_id === (int) $type->id
            && ($space = $context['spaces'][$unit->space_id] ?? null) && !$space->trashed())
            ->sortBy($order)->values();
        // a reservation of a unit not shown (inactive / moved): its own row, so nothing is hidden
        $missing = $reservations->pluck('unit_id')->unique()->diff($units->pluck('id'));
        $units = $units->concat($missing->map(fn ($id) => $context['units'][$id] ?? null)->filter())->values();

        $rows = $units->map(fn ($unit) => $this->timelineRow($unit, $reservations->where('unit_id', $unit->id), $windowStart, $windowEnd, $slots));
        $spaces = $units->pluck('space_id')->unique()->map(fn ($id) => $context['spaces'][$id] ?? null)->filter()->sortBy($order)->values();
        $groups = $spaces->map(fn ($space) => ['space' => $space, 'rows' => $rows->filter(fn ($row) => (int) $row['unit']->space_id === (int) $space->id)->values()])
            ->filter(fn ($group) => $group['rows']->isNotEmpty())->values();

        return [
            'mode' => 'hourly',
            'type' => $type,
            'cancelled' => $cancelled,
            'anchor' => 'today-type-' . $type->id,
            'count' => $own->count(),
            'due' => $this->dueOf($own),
            'slots' => $slots,
            'hours' => range(0, intdiv($slots * self::SLOT_MINUTES, 60) - 1),
            'groups' => $groups,
            'multiSpace' => $groups->count() > 1,
            'hasEarlier' => $rows->contains(fn ($row) => $row['earlier']->isNotEmpty()),
            'hasLater' => $rows->contains(fn ($row) => $row['later']->isNotEmpty()),
            // the List view: the ones from yesterday first, then by start
            // the List view = the reservations list columns (plan, Created By, the row menu's Renew check)
            'list' => $reservations->sortBy(fn ($reservation) => [$reservation->today_own ? 1 : 0, $reservation->start_at->getTimestamp(), $reservation->id])->values(),
        ];
    }

    /**
     * One unit row of the timeline: 'earlier' (ended before the window), 'later' (starting after it), 'blocks' in the grid
     * ['reservation', 'from' (slot 1…), 'span', 'lane' (1…), 'clipStart', 'clipEnd'], 'lanes'
     */
    private function timelineRow(Unit $unit, Collection $reservations, Carbon $windowStart, Carbon $windowEnd, int $slots): array
    {
        $earlier = collect();
        $later = collect();
        $blocks = [];
        $laneEnds = [];
        foreach ($reservations as $reservation) {
            $start = $reservation->start_at;
            $end = $reservation->end_at ? $reservation->realEnd() : $windowEnd;
            if ($end->lte($windowStart)) {
                $earlier->push($reservation);
                continue;
            }
            if ($start->gte($windowEnd)) {
                $later->push($reservation);
                continue;
            }
            $from = intdiv((int) max(0, $windowStart->diffInMinutes($start, false)), self::SLOT_MINUTES) + 1;
            $until = (int) ceil(min($windowStart->diffInMinutes($windowEnd), $windowStart->diffInMinutes($end, false)) / self::SLOT_MINUTES);
            $span = max(1, min($slots - $from + 1, $until - $from + 1));
            // the first lane free at its start (else the last lane)
            $lane = 1;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $from && $lane < self::MAX_LANES) {
                $lane++;
            }
            $laneEnds[$lane] = max($laneEnds[$lane] ?? 0, $from + $span);
            $blocks[] = ['reservation' => $reservation, 'from' => $from, 'span' => $span, 'lane' => $lane,
                'clipStart' => $start->lt($windowStart), 'clipEnd' => $end->gt($windowEnd)];
        }

        return ['unit' => $unit, 'earlier' => $earlier, 'later' => $later, 'blocks' => $blocks, 'lanes' => max(1, count($laneEnds))];
    }

    private function fullDaySection(SubscriptionType $type, array $context): array
    {
        $date = $context['date'];
        // every status: the cancelled ones only count for the summary "Cancelled"
        $all = $this->reservations($type, $context['accountId'], false)
            ->where('start_at', '>=', $date)->where('start_at', '<', $date->copy()->addDay())
            ->orderBy('created_at')->orderBy('id')->get();
        $cancelled = $all->filter(fn ($reservation) => $this->notCounted($reservation))->count();
        $rows = $all->reject(fn ($reservation) => $this->notCounted($reservation))
            // "In": the check-in time — imported rows keep the old start time, the app saves the date only (00:00) → when added
            ->each(function ($reservation) {
                $reservation->today_in = $reservation->start_at->format('H:i:s') === '00:00:00' ? $reservation->created_at : $reservation->start_at;
            })
            ->sortBy(fn ($reservation) => [$reservation->today_in?->getTimestamp() ?? 0, $reservation->id])->values();

        return [
            'mode' => 'full_day',
            'type' => $type,
            'cancelled' => $cancelled,
            'anchor' => 'today-type-' . $type->id,
            'count' => $rows->count(),
            'due' => $this->dueOf($rows),
            'rows' => $rows,
        ];
    }

    private function periodSection(SubscriptionType $type, array $context): array
    {
        $date = $context['date'];
        $day = $date->toDateString();
        $last = $date->copy()->addDays($context['renewalDays'])->toDateString();
        $renewalDate = Reservation::RENEWAL_DATE_SQL;

        $dayEnd = $date->copy()->addDay();

        // ONE query (the type is a period type: renewalPeriods()' rules without its type lookup): renewing on D … D+N, or before
        // D and not renewed — then split by the renewal date
        // + the new subscriptions starting on D (Starts today: counted, no renewal_of, not left; 2026-10-19)
        // + the periods still owing (below)
        // + the cancelled like ones starting on D (the summary "Cancelled")
        $all = $this->reservations($type, $context['accountId'], false)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->counted()
                    ->where('reservations.member_id', '>', 0)->whereNotNull('reservations.end_at')->whereNull('reservations.left_at')
                    ->where(fn ($query) => $query->whereRaw("$renewalDate BETWEEN ? AND ?", [$day, $last])
                        ->orWhere(fn ($query) => $query->whereRaw("$renewalDate < ?", [$day])->notRenewed())))
                ->orWhere(fn ($query) => $query->counted()->whereNull('reservations.renewal_of')->whereNull('reservations.left_at')
                    ->where('start_at', '>=', $date)->where('start_at', '<', $dayEnd))
                // + the periods started on / before D still owing (Not renewed (b), payment due at the start; 2026-10-19): the chain's
                // current one is picked by periodGroups() (no renewal, or one starting after D)
                ->orWhere(fn ($query) => $query->counted()->where('reservations.member_id', '>', 0)->whereNotNull('reservations.end_at')
                    ->whereNull('reservations.left_at')->where('reservations.package_id', 0)->where('reservations.remaining', '>', 0)
                    ->where('start_at', '<', $dayEnd))
                ->orWhere(fn ($query) => $query->whereIn('reservation_status_id', ReservationStatus::notCountedIds() ?: [0])
                    ->where('start_at', '>=', $date)->where('start_at', '<', $dayEnd)))
            ->get();
        $cancelled = $all->filter(fn ($reservation) => $this->notCounted($reservation))->count();
        $periods = $all->reject(fn ($reservation) => $this->notCounted($reservation))->values()
            ->each(fn ($reservation) => $reservation->renews_on = $reservation->renewalDate()->toDateString());

        return [
            'mode' => 'period',
            'type' => $type,
            'cancelled' => $cancelled,
            'anchor' => 'today-renewals-' . $type->id,
            // the Starts today group (the summary "New" scrolls to it)
            'startsAnchor' => 'today-starts-' . $type->id,
            // the counted rows; split into the groups by periodGroups() after hydrate() (renewedBy)
            'periods' => $periods,
            'renewalDays' => $context['renewalDays'],
        ];
    }

    /**
     * The period section's groups (after hydrate(): renewedBy = the counted renewal, its remaining), user 2026-10-29: a row shows the
     * period that covers the selected date D. Payment is due at the start of a period.
     * - Starts today: new subscriptions starting on D (no renewal_of).
     * - Renews today (renewal date = D): a renewal exists → the RENEWAL is the row (owing → listed with Pay; paid → not listed);
     *   none → the period with Renew (Mark left).
     * - Not renewed: (a) ended before D, no renewal, not left → Renew / Mark left (+ its own Pay); a renewal started on / before D
     *   still owing → the renewal (due since its start, Pay); (b) a running period still owing whose renewal date is after the
     *   window → Pay (due since its start).
     * - Next N days (renewal date D+1 … D+N): the current period; a renewal made in advance is not mentioned (no Renew), Pay =
     *   its own debt first, else the advance renewal's (renewal-row: pay_target).
     * - A past D: no Next N days / Not renewed (they use today's payments). needAction = Renews today rows + Not renewed.
     */
    private function periodGroups(array $section, array $context): array
    {
        $date = $context['date'];
        $day = $date->toDateString();
        $dayEnd = $date->copy()->addDay();
        $last = $date->copy()->addDays($context['renewalDays'])->toDateString();
        $periods = $section['periods'];
        $owes = fn ($reservation) => $reservation && $reservation->canTakePayments() && $reservation->remaining > 0;
        $isPeriodRow = fn ($reservation) => $reservation->member_id > 0 && $reservation->end_at && $reservation->left_at === null;
        // a renewal shown as the row (the period that covers D)
        $asRenewal = function ($old) {
            $renewal = $old->renewedBy;
            $renewal->renewal_row = true;
            $renewal->renewal_for = $old->id;
            $renewal->overdue_kind = 'pay';
            $renewal->due_since = $renewal->start_at->toDateString();
            return $renewal;
        };

        // a new subscription starting on D: space order, unit order, member name
        $startsToday = $periods->filter(fn ($reservation) => $reservation->renewal_of === null && $reservation->left_at === null
            && $reservation->start_at && $reservation->start_at->gte($date) && $reservation->start_at->lt($dayEnd))
            ->sortBy(fn ($reservation) => [(int) $reservation->space?->sort_order, (int) $reservation->unit?->sort_order,
                mb_strtolower((string) $reservation->member?->name), $reservation->id])->values();
        $startIds = $startsToday->pluck('id')->all();

        // Renews today: the renewal (owing; a paid one is not listed) or the period itself (no renewal)
        $renewsToday = $periods->filter(fn ($reservation) => $isPeriodRow($reservation) && $reservation->renews_on === $day)
            ->filter(fn ($reservation) => !$reservation->renewedBy || $owes($reservation->renewedBy))
            ->map(fn ($reservation) => $reservation->renewedBy ? $asRenewal($reservation) : $reservation)
            ->sortBy(fn ($reservation) => [(int) $reservation->member_id, $reservation->id])->values();

        // Not renewed (a): ended before D, no renewal
        $ended = $periods->filter(fn ($reservation) => $isPeriodRow($reservation) && $reservation->renews_on < $day
            && !$reservation->renewedBy && $reservation->start_at?->lt($date))
            ->each(function ($reservation) {
                $reservation->overdue_kind = 'renew';
                $reservation->due_since = $reservation->renews_on;
            });
        // Not renewed: a renewal started on / before D still owing (its period renewed before D)
        $renewalsOwing = $periods->filter(fn ($reservation) => $isPeriodRow($reservation) && $reservation->renews_on < $day
            && $reservation->renewedBy && $reservation->renewedBy->start_at?->lt($dayEnd) && $owes($reservation->renewedBy))
            ->map(fn ($reservation) => $asRenewal($reservation));

        // Next N days: the current period (an advance renewal is not mentioned; Pay = its own debt, else the renewal's)
        $next = $periods->filter(fn ($reservation) => $isPeriodRow($reservation) && $reservation->renews_on > $day && $reservation->renews_on <= $last)
            ->each(function ($reservation) use ($owes) {
                $reservation->pay_target = $owes($reservation) ? $reservation : ($owes($reservation->renewedBy) ? $reservation->renewedBy : null);
            })
            ->sortBy(fn ($reservation) => [$reservation->renews_on, $reservation->id])->values();

        // Not renewed (b): a running period still owing, not listed above (its renewal date after the window)
        $listed = collect([$startIds, $renewsToday->pluck('id')->all(), $renewsToday->pluck('renewal_for')->filter()->all(),
            $ended->pluck('id')->all(), $renewalsOwing->pluck('id')->all(), $next->pluck('id')->all()])->flatten()->map(fn ($id) => (int) $id)->all();
        $unpaid = $periods->filter(fn ($reservation) => $isPeriodRow($reservation) && $owes($reservation)
            && $reservation->start_at?->lt($dayEnd) && !in_array((int) $reservation->id, $listed, true)
            && (!$reservation->renewedBy || $reservation->renewedBy->start_at?->gte($dayEnd)))
            ->each(function ($reservation) {
                $reservation->overdue_kind = 'pay';
                $reservation->due_since = $reservation->start_at->toDateString();
                // a renewal (its old period is no longer listed once renewed): "renewal of #…" under the name
                if ($reservation->renewal_of) {
                    $reservation->renewal_row = true;
                    $reservation->renewal_for = $reservation->renewal_of;
                }
            });
        // the fewest days since it was due first (user 2026-10-15); a renewal row once only
        $notRenewed = $ended->concat($renewalsOwing)->concat($unpaid)->unique('id')
            ->sortBy(fn ($reservation) => [$reservation->due_since, $reservation->id])->reverse()->values();

        // a past day: today's payment state would mislead (user 2026-10-29)
        if (!empty($context['isPast'])) {
            $notRenewed = collect();
            $next = collect();
        }

        return array_merge($section, [
            'startsToday' => $startsToday,
            'renewsToday' => $renewsToday,
            'notRenewed' => $notRenewed,
            'next' => $next,
            'isPast' => !empty($context['isPast']),
            // the summary "Renewals": every Renews today row needs an action (Renew or Pay) + not renewed
            'needAction' => $renewsToday->count() + $notRenewed->count(),
        ]);
    }
}
