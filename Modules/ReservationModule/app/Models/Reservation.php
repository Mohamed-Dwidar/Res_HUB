<?php

namespace Modules\ReservationModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Contracts\Payable;
use Modules\PaymentModule\app\Models\Concerns\HasPayments;
use Modules\PlanModule\app\Models\Plan;
use Modules\SpaceModule\app\Models\Space;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UnitModule\app\Models\Unit;

/**
 * A reservation of a unit (of a space) with a plan for a member, made by the account or one of its employees.
 * net_amount = amount - discount_value (discount_percentage is the same discount in %).
 * repeat_id: the reservation this one repeats (repeat()), repeats(): the reservations made from this one.
 * renewal_of: the period this one renews (period types: Renew, repeat copies; renewedBy() = its counted renewal, at most one);
 * left_at / left_by: a period marked "left" (not renewed on purpose; left_by 0 = auto-marked by to_run STEP 5b).
 */
class Reservation extends Model implements Payable
{
    use HasPayments, SafeMorphHelper, SoftDeletes;

    // the most copies one "repeat until" can create
    const MAX_REPEATS = 365;

    const REPEAT_FREQUENCIES = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
    ];

    protected $fillable = [
        'account_id', 'member_id', 'space_id', 'unit_id', 'plan_id', 'package_id', 'subscription_type_id', 'reservation_status_id',
        'start_at', 'end_at', 'subscription_day', 'all_day', 'repeat_id', 'is_repeat', 'repeat_frequency', 'repeat_interval',
        'renewal_of', 'left_at', 'left_by', 'number_of_peoples', 'amount', 'discount_percentage', 'discount_value', 'net_amount', 'paid_amount', 'remaining', 'notes',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'left_at' => 'datetime',
        'all_day' => 'boolean',
        'is_repeat' => 'boolean',
        'amount' => 'float',
        'discount_percentage' => 'float',
        'discount_value' => 'float',
        'net_amount' => 'float',
        'paid_amount' => 'float',
        // the tax snapshot (docs/payments-plan.md §3, ReservationService via TaxSnapshot): set by the service, never mass assigned
        'invoice_requested' => 'boolean',
        'tax_vat_rate' => 'float',
        'tax_vat_exempt' => 'boolean',
        'tax_table_rate' => 'float',
        'tax_inclusive' => 'boolean',
        'tax_net' => 'float',
        'table_tax' => 'float',
        'vat' => 'float',
        'tax_extra_discount' => 'float',
        'tax_total' => 'float',
        'remaining' => 'float',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // still shown when the related record was deleted later
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function subscriptionType(): BelongsTo
    {
        return $this->belongsTo(SubscriptionType::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ReservationStatus::class, 'reservation_status_id')->withTrashed();
    }

    // the reservation this one repeats (self relation)
    public function repeat(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeat_id');
    }

    // the reservations made by repeating this one
    public function repeats(): HasMany
    {
        return $this->hasMany(self::class, 'repeat_id');
    }

    // the period this one renews
    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of');
    }

    // the counted (not cancelled like) reservation that renews this period: "renewed" (at most one, ReservationService)
    public function renewedBy(): HasOne
    {
        return $this->hasOne(self::class, 'renewal_of')->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds() ?: [0]);
    }

    // a period of a period type (subscription / contract): it can be renewed, marked left
    public function isPeriod(): bool
    {
        // its type already loaded (the Schedule page sets it): no lookup
        if ($this->relationLoaded('subscriptionType') && $this->subscriptionType) {
            return $this->subscriptionType->booking_mode === 'period';
        }
        return in_array((int) $this->subscription_type_id, SubscriptionType::idsOf('period'), true);
    }

    // who added the reservation (users: the account or an employee)
    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // what it costs the member (taxes, docs/payments-plan.md §3): the tax total when an invoice is requested and it was taxed,
    // else the net amount (the price after discount, as before taxes); DUE_SQL = the same in SQL
    const DUE_SQL = 'CASE WHEN reservations.invoice_requested = 1 AND reservations.tax_total IS NOT NULL THEN reservations.tax_total ELSE reservations.net_amount END';

    public function isTaxed(): bool
    {
        return $this->invoice_requested && $this->tax_total !== null;
    }

    public function dueAmount(): float
    {
        return $this->isTaxed() ? (float) $this->tax_total : (float) $this->net_amount;
    }

    // Payable: what it costs (dueAmount()), 0 with a not counted status (cancelled ...); inside a package it is paid through the package
    public function payableAmount(): float
    {
        return $this->status && !$this->status->is_counted ? 0 : $this->dueAmount();
    }

    public function payableMemberId(): int
    {
        return (int) $this->member_id;
    }

    public function canTakePayments(): bool
    {
        return (int) $this->package_id === 0;
    }

    // HasPayments::scopePaymentStatus(): paid on its own / through its package
    public function scopePayableAlone($query)
    {
        return $query->where('reservations.package_id', 0);
    }

    public function scopeNotPayableAlone($query)
    {
        return $query->where('reservations.package_id', '>', 0);
    }

    public function getRepeatFrequencyLabelAttribute(): ?string
    {
        return self::REPEAT_FREQUENCIES[$this->repeat_frequency] ?? null;
    }

    // the display formats of the start / end (a time is shown only for a time based plan)
    const DATE_FORMAT = 'd-m-Y';
    const TIME_FORMAT = 'h:i A';

    // "21-10-2026" / "21-10-2026 | 11:00 AM" (time based plan)
    public function formatAt($at): string
    {
        if (!$at) {
            return '-';
        }
        return $at->format(self::DATE_FORMAT) . ($this->plan?->is_time_based ? ' | ' . $at->format(self::TIME_FORMAT) : '');
    }

    // the same day:  "21-10-2026 | 11:00 AM → 01:30 PM" (time based) / "21-10-2026"
    // other days:    "21-10-2026 | 11:00 AM → 22-10-2026 | 01:30 PM" / "21-10-2026 → 25-10-2026"
    // no end (old data fallback): "21-10-2026 → -"
    public function getPeriodAttribute(): string
    {
        if (!$this->start_at) {
            return '-';
        }
        if (!$this->end_at) {
            return $this->formatAt($this->start_at) . ' → -';
        }
        if ($this->start_at->isSameDay($this->end_at)) {
            return $this->plan?->is_time_based
                ? $this->formatAt($this->start_at) . ' → ' . $this->end_at->format(self::TIME_FORMAT)
                : $this->start_at->format(self::DATE_FORMAT);
        }
        return $this->formatAt($this->start_at) . ' → ' . $this->formatAt($this->end_at);
    }

    // the starts of the repeated copies: every $interval $frequency after $start, until the date $until (included);
    // from the first start each time (Jan 31 monthly => Feb 28, Mar 31 ...), at most $limit
    public static function repeatStarts(Carbon $start, string $frequency, int $interval, Carbon $until, int $limit = self::MAX_REPEATS + 1): array
    {
        $starts = [];
        for ($k = 1; count($starts) < $limit; $k++) {
            $step = $k * max(1, $interval);
            $next = match ($frequency) {
                'daily' => $start->copy()->addDays($step),
                'weekly' => $start->copy()->addWeeks($step),
                'yearly' => $start->copy()->addYearsNoOverflow($step),
                default => $start->copy()->addMonthsNoOverflow($step),
            };
            if ($next->toDateString() > $until->toDateString()) {
                break;
            }
            $starts[] = $next;
        }
        return $starts;
    }

    // [discount_value, discount_percentage, net_amount] from the amount and the one the user typed ($type: percentage | value | net)
    public static function calculate(float $amount, ?float $percentage, ?float $value, string $type = 'percentage', ?float $net = null): array
    {
        $amount = max(0, $amount);
        if ($type === 'net') {
            $value = $amount - min(max(0, (float) $net), $amount);
            $percentage = $amount > 0 ? $value / $amount * 100 : 0;
        } elseif ($type === 'value') {
            $value = min(max(0, (float) $value), $amount);
            $percentage = $amount > 0 ? $value / $amount * 100 : 0;
        } else {
            $percentage = min(max(0, (float) $percentage), 100);
            $value = $amount * $percentage / 100;
        }

        return [round($value, 2), round($percentage, 2), round($amount - $value, 2)];
    }

    // the counted reservations of the unit that overlap start -> end (no end = it continues): they start before the end
    // and end after the start (or continue); the deleted ones and the not counted statuses (cancelled ...) are not counted
    public function scopeOverlapping($query, $unitId, $start, $end = null)
    {
        $query->where('unit_id', $unitId)
            ->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds())
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>', $start));
        if ($end) {
            $query->where('start_at', '<', $end);
        }
        return $query;
    }

    // the list tabs (docs/reservations-list-plan.md, simplified 2026-10-13): today (default, running today) | upcoming | all
    const TABS = [
        'today' => 'Today',
        'upcoming' => 'Upcoming',
        'all' => 'All',
    ];

    /**
     * The list date field of each tab (layoutmodule::partials.date-range-filter): select = the days it shows (Y-m-d, "from,to";
     * picking them highlights the tab), label = the text of a tab with its own rule (no days selected). All = empty.
     */
    public static function tabDates(): array
    {
        [$today] = self::businessToday();
        return [
            'today' => ['select' => $today->toDateString()],
            'upcoming' => ['label' => 'From ' . $today->copy()->addDay()->format(self::DATE_FORMAT)],
        ];
    }

    /**
     * [the business date, its cutoff hours] of the logged in account (Account::businessDate(), Settings › General): at night,
     * before the cutoff (e.g. 02:00), "today" is still the day before; [today(), 0] without an account.
     */
    public static function businessToday(): array
    {
        $account = Account::current();
        return $account ? [$account->businessDate(), $account->cutoffHours()] : [today(), 0];
    }

    // the hourly reservations starting after midnight, before the cutoff (the night of the business date $today)
    public function scopeNightOf($query, Carbon $today, int $cutoff)
    {
        $midnight = $today->copy()->addDay();
        return $query->where('start_at', '>=', $midnight)->where('start_at', '<', $midnight->copy()->addHours($cutoff))
            ->whereNotIn('subscription_type_id', SubscriptionType::idsOf('full_day') ?: [0]);
    }

    /**
     * Running at some moment of [from, to): starts before "to" and ends after "from".
     * An end before the start (old same-day rows) = it ends at its start: it counts on its start day.
     * Plain ranges (no whereDate), so the (account_id, start_at) / (account_id, end_at) indexes can be used.
     */
    public function scopeRunningBetween($query, Carbon $from, Carbon $to)
    {
        return $query->where('start_at', '<', $to)
            ->where(fn ($query) => $query->where('end_at', '>', $from)->orWhere('start_at', '>=', $from)->orWhereNull('end_at'));
    }

    /**
     * The rows of a list tab (an unknown tab = all), by the subscription type's booking mode (user 2026-10-23, like the old system):
     * - hourly / full day: Today = running on the business date (overlap + its night), Upcoming = starting after it;
     * - period (booking_mode = period): Today = starting on the business date (New / Renewal) OR renewing on it and not
     *   renewed-and-paid (Renewal due, the Schedule page rule) — not the days in between; Upcoming = starting after it OR
     *   renewing in the next renewal_days (Settings › General) and not renewed-and-paid.
     * + 'days' (not a tab button): days picked in the list date field (filters date_from / date_to) = the Today rule for those days.
     */
    public function scopeTab($query, ?string $tab, array $filters = [])
    {
        // the business date (Settings › General): its night (hourly reservations after midnight, before the cutoff) is on
        // Today, not on Upcoming; the full day ones go by their date
        [$today, $cutoff] = self::businessToday();
        $tomorrow = $today->copy()->addDay();
        $periodIds = SubscriptionType::idsOf('period') ?: [0];
        // hourly / full day rows (not period)
        $notPeriod = fn ($query) => $query->whereNotIn('reservations.subscription_type_id', $periodIds);
        // period rows starting in [from, to) or renewing in it
        $periodIn = fn ($query, Carbon $from, Carbon $to) => $query->whereIn('reservations.subscription_type_id', $periodIds)
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('reservations.start_at', '>=', $from)->where('reservations.start_at', '<', $to))
                ->orWhere(fn ($query) => $query->renewalDueBetween($from, $to)));

        return match ($tab) {
            'today' => $query->where(fn ($query) => $query
                ->where(fn ($query) => $notPeriod($query)->where(fn ($query) => $query->runningBetween($today, $tomorrow)
                    ->when($cutoff, fn ($query) => $query->orWhere(fn ($query) => $query->nightOf($today, $cutoff)))))
                ->orWhere(fn ($query) => $periodIn($query, $today, $tomorrow))),
            'days' => $query->where(fn ($query) => $query
                ->where(fn ($query) => $notPeriod($query)->runningBetween(...self::pickedDays($filters)))
                ->orWhere(fn ($query) => $periodIn($query, ...self::pickedDays($filters)))),
            'upcoming' => $query->where(fn ($query) => $query
                ->where(fn ($query) => $notPeriod($query)->where('reservations.start_at', '>=', $tomorrow)
                    ->when($cutoff, fn ($query) => $query->whereNot(fn ($query) => $query->nightOf($today, $cutoff))))
                ->orWhere(fn ($query) => $periodIn($query, $tomorrow, $tomorrow->copy()->addDays(self::renewalDaysOf())))
                // a period starting after the renewals window is still upcoming
                ->orWhere(fn ($query) => $query->whereIn('reservations.subscription_type_id', $periodIds)->where('reservations.start_at', '>=', $tomorrow))),
            default => $query,
        };
    }

    // the renewals window of the logged in account (Settings › General, 7 days by default)
    public static function renewalDaysOf(): int
    {
        return Account::current()?->renewalDays() ?? 7;
    }

    /**
     * Period rows whose renewal date (RENEWAL_DATE_SQL: the day after the last day) is in [from, to) and that are not
     * renewed-and-paid (the Schedule page: a counted renewal that owes nothing, or is paid through its package); counted, with
     * a member and an end, not marked left (scopeRenewalPeriods).
     */
    public function scopeRenewalDueBetween($query, Carbon $from, Carbon $to)
    {
        return $query->renewalPeriods()->renewalDateBetween($from, $to)->whereRaw('NOT ' . self::paidRenewalSql());
    }

    /**
     * The renewal date (RENEWAL_DATE_SQL) in [from, to), as ranges on start_at / end_at (no DATE(), the indexes can be used):
     * the last day in [from − 1, to − 1) = GREATEST(end, start) in it.
     */
    public function scopeRenewalDateBetween($query, Carbon $from, Carbon $to)
    {
        $lo = $from->copy()->subDay();
        $hi = $to->copy()->subDay();
        return $query->where('reservations.start_at', '<', $hi)->where('reservations.end_at', '<', $hi)
            ->where(fn ($query) => $query->where('reservations.end_at', '>=', $lo)->orWhere('reservations.start_at', '>=', $lo));
    }

    /**
     * SQL: the period ($alias) is renewed AND paid — a counted, not deleted renewal (renewal_of) that owes nothing
     * (remaining ≤ 0) or is paid through its package (the Schedule page rule, payment due at the start of a period).
     */
    public static function paidRenewalSql(string $alias = 'reservations', string $renewal = 'paid_renewal'): string
    {
        $notCounted = implode(',', array_map('intval', ReservationStatus::notCountedIds()) ?: [0]);
        return "EXISTS (SELECT 1 FROM reservations {$renewal} WHERE {$renewal}.renewal_of = {$alias}.id AND {$renewal}.deleted_at IS NULL"
            . " AND {$renewal}.reservation_status_id NOT IN ({$notCounted}) AND ({$renewal}.package_id > 0 OR {$renewal}.remaining <= 0))";
    }

    // the list "Renewal" filter (2026-10-23; the Schedule lists, 2026-10-29): not_paid | upcoming | renewed | left
    const RENEWAL_FILTERS = [
        'not_paid' => 'Not renewed & Not paid',
        'upcoming' => 'Upcoming',
        'renewed' => 'Renewed',
        'left' => 'Left',
    ];

    // SQL: the period still owes on its own (paid on its own, remaining > 0)
    const OWES_SQL = '(reservations.package_id = 0 AND reservations.remaining > 0)';

    /**
     * The list "Renewal" filter: period rows (counted, member, end) — the Schedule's lists (user 2026-10-29):
     * - not_paid (as of $asOf, the Schedule's "Not renewed & Not paid"): not left, AND (started by $asOf and still owing — due since
     *   its start, in [from, to)) OR (paid, ended by $asOf with no renewal — due since its renewal date, in [from, to));
     * - upcoming: not left, renewal date in [from, to), renewed or not, paid or not (the Renewal column describes the renewal);
     * - renewed: not left, renewal date in [from, to), renewed AND paid (paidRenewalSql());
     * - left: renewal date in [from, to), marked left (left_at).
     */
    public function scopeRenewalStatus($query, string $status, Carbon $from, Carbon $to, ?Carbon $asOf = null)
    {
        $query->whereIn('reservations.subscription_type_id', SubscriptionType::idsOf('period') ?: [0])
            ->counted()->where('reservations.member_id', '>', 0)->whereNotNull('reservations.end_at');

        if ($status === 'left') {
            return $query->renewalDateBetween($from, $to)->whereNotNull('reservations.left_at');
        }
        $query->whereNull('reservations.left_at');
        if ($status === 'renewed') {
            return $query->renewalDateBetween($from, $to)->whereRaw(self::paidRenewalSql());
        }
        if ($status === 'upcoming') {
            return $query->renewalDateBetween($from, $to);
        }

        // not_paid: up to the day after $asOf (default the range's last day)
        $until = ($asOf ?? $to->copy()->subDay())->copy()->startOfDay()->addDay();
        $until = $until->lt($to) ? $until : $to->copy();
        return $query->where(fn ($query) => $query
            ->where(fn ($query) => $query->whereRaw(self::OWES_SQL)
                ->where('reservations.start_at', '>=', $from)->where('reservations.start_at', '<', $until))
            ->orWhere(fn ($query) => $query->whereRaw('NOT ' . self::OWES_SQL)->renewalDateBetween($from, $until)
                ->whereRaw('NOT ' . self::renewedSql())));
    }

    /**
     * The list badge of a period row on Today / Upcoming / picked days (null otherwise): "New" / "Renewal" on its start,
     * "Renewal due" on its renewal date (Upcoming: + the date). $from / $to = the tab's days.
     */
    public function listBadge(?string $tab, array $filters = []): ?array
    {
        if (!in_array($tab, ['today', 'upcoming', 'days'], true) || !$this->isPeriod() || !$this->start_at) {
            return null;
        }
        [$today] = self::businessToday();
        [$from, $to] = match ($tab) {
            'today' => [$today, $today->copy()->addDay()],
            'days' => self::pickedDays($filters),
            'upcoming' => [$today->copy()->addDay(), null],
        };
        if ($this->start_at->gte($from) && (!$to || $this->start_at->lt($to))) {
            return $this->renewal_of ? ['Renewal', 'badge-status-primary'] : ['New', 'badge-status-success'];
        }
        $date = $this->renewalDate();
        return ['Renewal due' . ($tab === 'upcoming' && $date ? ' ' . $date->format(self::DATE_FORMAT) : ''), 'badge-status-warning'];
    }

    // the default order of a tab (until a column is clicked): today = starting today first (by start time), then the
    // ones already running (long ones) / renewing; upcoming = nearest first (a period renewing: by its renewal date);
    // all = newest first
    // the days picked in the list date field: [first day 00:00, the day after the last 00:00)
    public static function pickedDays(array $filters): array
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        return [$from, Carbon::parse($filters['date_to'] ?? null ?: $from)->addDay()->startOfDay()];
    }

    public function scopeTabOrder($query, ?string $tab, array $filters = [])
    {
        // starting in the period first, then the running ones
        if (in_array($tab, ['today', 'days'], true)) {
            $query->orderByRaw('start_at >= ? DESC', [$tab === 'days' ? self::pickedDays($filters)[0] : self::businessToday()[0]]);
        }
        // upcoming: a period shown for its renewal goes by its renewal date
        if ($tab === 'upcoming') {
            return $query->orderByRaw('CASE WHEN start_at >= ? THEN start_at ELSE ' . self::RENEWAL_DATE_SQL . ' END', [self::businessToday()[0]->copy()->addDay()])
                ->orderBy('id');
        }

        return in_array($tab, ['today', 'days'], true)
            ? $query->orderBy('start_at')->orderBy('id')
            : $query->orderByDesc('start_at')->orderByDesc('id');
    }

    public function scopeCounted($query)
    {
        return $query->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds());
    }

    // counted (its status counts: not cancelled / pending ...); no status = counted
    public function isCounted(): bool
    {
        return $this->status?->is_counted ?? true;
    }

    // the end used for the length / the badges: an end before the start (old same-day rows) = the start
    public function realEnd(): ?Carbon
    {
        return $this->end_at && $this->start_at && $this->end_at->lt($this->start_at) ? $this->start_at : $this->end_at;
    }

    /**
     * The badge of a reservation longer than a day (the list Period column): "running · day 12 of 90" while it runs,
     * "ends 30-11" before, "ended 30-11" after (the year only when not this year). null for a short one.
     */
    public function getLongBadgeAttribute(): ?string
    {
        $end = $this->realEnd();
        if (!$this->start_at || !$end || $this->start_at->diffInHours($end) <= 24) {
            return null;
        }

        $date = $end->format($end->year === now()->year ? 'd-m' : 'd-m-Y');
        if (now()->lt($this->start_at)) {
            return 'ends ' . $date;
        }
        if (now()->gte($end)) {
            return 'ended ' . $date;
        }
        $days = (int) ceil($this->start_at->diffInMinutes($end) / 1440);
        $day = min($days, (int) floor($this->start_at->diffInMinutes(now()) / 1440) + 1);

        return 'running · day ' . $day . ' of ' . $days;
    }

    // the Subscriptions tab's filter (docs/reservations-list-plan.md)
    const SUBSCRIPTION_TABS = [
        'active' => 'Active Now',
        'ending7' => 'Ending within 7 Days',
        'ending30' => 'Ending within 30 Days',
        'ended' => 'Ended, Not Renewed',
    ];

    // "ended, not renewed": ended within this many days by default (a date range looks further back)
    const ENDED_DAYS = 60;

    // the ids of the contract subscription types (subscription_types.is_contract: monthly subscriptions, one row per period)
    public static function contractTypeIds(): array
    {
        return once(fn () => SubscriptionType::where('is_contract', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    // contract periods (one reservation per renewal period)
    public function scopeContracts($query)
    {
        return $query->whereIn('subscription_type_id', self::contractTypeIds() ?: [0]);
    }

    public function scopeNotContracts($query)
    {
        return ($ids = self::contractTypeIds()) ? $query->whereNotIn('subscription_type_id', $ids) : $query;
    }

    public function isContract(): bool
    {
        return in_array((int) $this->subscription_type_id, self::contractTypeIds(), true);
    }

    /**
     * SQL: renewed = a counted, not deleted reservation points to it (renewal_of; per reservation, never per member — user
     * 2026-10-14), for "reservations" in the query.
     */
    public static function renewedSql(): string
    {
        $notCounted = array_map('intval', ReservationStatus::notCountedIds()) ?: [0];

        return 'EXISTS (SELECT 1 FROM reservations next WHERE next.renewal_of = reservations.id AND next.deleted_at IS NULL'
            . ' AND next.reservation_status_id NOT IN (' . implode(',', $notCounted) . '))';
    }

    // the end date of a period as SQL: its last day (DATE of the end; an end before the start = the start, realEnd())
    const END_DATE_SQL = 'DATE(GREATEST(reservations.end_at, reservations.start_at))';

    // the renewal date of a period as SQL: the day after its last day (= the start of its renewal, renewalOf()); the Today renewals
    const RENEWAL_DATE_SQL = 'DATE_ADD(' . self::END_DATE_SQL . ', INTERVAL 1 DAY)';

    // the renewal date (the day after its last day = DATE of the real end; an imported end at 00:00 is that day itself, an app
    // end is 23:59:59 of it): the same day its renewal starts (ReservationService::renewalOf())
    public function renewalDate(): ?Carbon
    {
        return ($this->realEnd() ?? $this->start_at)?->copy()->startOfDay()->addDay();
    }

    /**
     * Renew rules (user 2026-10-29, revised the same day): anyone (the owner AND employees) may renew a period that still owes —
     * the Renew form shows what the member will owe across their periods and asks a reason (ReservationService::unpaidRenewalNote(),
     * logged in reservation_events → Payment Exceptions › Unpaid Renewals). renewalDebt() = what this period still owes on its own
     * (0 inside a package); canRenew(): a counted period with no counted renewal yet.
     */
    public function renewalDebt(): float
    {
        return $this->canTakePayments() && $this->remaining > 0 ? round((float) $this->remaining, 2) : 0.0;
    }

    public function canRenew(): bool
    {
        return $this->isPeriod() && $this->isCounted() && !$this->renewedBy;
    }

    // the member's periods (period types, counted, paid on their own) that still owe, oldest first: the Renew form's total
    public static function owingPeriodsOf(int $accountId, int $memberId): Collection
    {
        return $memberId > 0 ? static::where('account_id', $accountId)->where('member_id', $memberId)
            ->whereIn('subscription_type_id', SubscriptionType::idsOf('period') ?: [0])->counted()
            ->where('package_id', 0)->where('remaining', '>', 0)->orderBy('start_at')->orderBy('id')->get() : collect();
    }

    /**
     * The end of a period that starts on $start, by the plan's lease period and the anchor day (subscription_day, the day of month
     * the subscription renews on; user 2026-10-23, calendar months like the old system): month → the next start = the anchor day
     * of the month after $start's month, clamped to its last day (31 → 28/29 Feb → 31 Mar → 30 Apr); year → the same date next
     * year (29-02 → 28-02). The end = that next start − 1 day, 23:59:59. null for other lease periods (the caller keeps its rule).
     * The same rule in custom.js reservationEndFromPlan().
     */
    public static function anchoredEnd(Carbon $start, ?int $anchor, ?string $leasePeriod): ?Carbon
    {
        $start = $start->copy()->startOfDay();
        $next = match ($leasePeriod) {
            'month' => (function () use ($start, $anchor) {
                $month = $start->copy()->startOfMonth()->addMonthNoOverflow();
                return $month->day(min($anchor ?: $start->day, $month->daysInMonth));
            })(),
            'year' => $start->copy()->addYearNoOverflow(),
            default => null,
        };

        return $next?->subDay()->endOfDay();
    }

    /**
     * The periods of the Schedule page renewals (period types, counted, with a member and an end, not marked left), by their
     * last day (END_DATE_SQL): ending on / between the dates; renewed or not decided by the caller.
     */
    public function scopeRenewalPeriods($query)
    {
        return $query->whereIn('reservations.subscription_type_id', SubscriptionType::idsOf('period') ?: [0])
            ->counted()->where('reservations.member_id', '>', 0)->whereNotNull('reservations.end_at')->whereNull('reservations.left_at');
    }

    // + the "renewed" column (0 / 1)
    public function scopeWithRenewed($query)
    {
        return $query->select('reservations.*')->selectRaw(self::renewedSql() . ' AS renewed');
    }

    public function scopeNotRenewed($query)
    {
        return $query->whereRaw('NOT ' . self::renewedSql());
    }

    /**
     * The Subscriptions tab's filter (counted contract periods with a member): active (running now, renewed or not) |
     * ending7 / ending30 (end within 7 / 30 days, not renewed) | ended (ended, not renewed: the member's latest one in that
     * space; ended within ENDED_DAYS days, or in the date range on the end date).
     */
    public function scopeSubscriptionTab($query, string $tab, array $filters = [])
    {
        $query->contracts()->counted()->where('member_id', '>', 0);
        $now = now();

        return match ($tab) {
            'ending7', 'ending30' => $query->where('end_at', '>', $now)->where('end_at', '<=', $now->copy()->addDays($tab === 'ending7' ? 7 : 30))->notRenewed(),
            'ended' => $query->where('end_at', '<=', $now)->notRenewed()
                ->where('end_at', '>=', !empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : $now->copy()->subDays(self::ENDED_DAYS)->startOfDay())
                ->when(!empty($filters['date_to']), fn ($query) => $query->where('end_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay())),
            default => $query->where('start_at', '<=', $now)->where(fn ($query) => $query->where('end_at', '>', $now)->orWhereNull('end_at')),
        };
    }

    // days left (running / future end) or days since it ended (negative)
    public function getDaysLeftAttribute(): ?int
    {
        $end = $this->realEnd();
        return $end ? (int) floor(now()->diffInMinutes($end, false) / 1440) : null;
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->whereHas('member', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', str_replace(' ', '', $search)));
        }

        foreach (['reservation_status_id', 'space_id', 'unit_id', 'package_id', 'member_id', 'subscription_type_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        // ranges (not whereDate) so the start_at index can be used
        if (!empty($filters['date_from'])) {
            $query->where('start_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $query->where('start_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        // unpaid / partial / paid / credit / nothing_due / elsewhere (in a package)
        if (!empty($filters['payment_status'])) {
            $query->paymentStatus($filters['payment_status']);
        }

        return $query;
    }
}
