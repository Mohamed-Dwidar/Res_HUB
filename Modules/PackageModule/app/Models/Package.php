<?php

namespace Modules\PackageModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;
use Modules\PaymentModule\app\Contracts\Payable;
use Modules\PaymentModule\app\Models\Concerns\HasExtras;
use Modules\PaymentModule\app\Models\Concerns\HasPayments;
use Modules\PaymentModule\app\Services\ExtraService;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\PaymentModule\app\Services\Tax\PackageTax;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationStatus;
use Modules\SpaceModule\app\Models\SubscriptionType;

/**
 * A package of a member: its price (amount) = the net amounts of its reservations,
 * - discount_percentage = after_discount (the package net), see recalculate() (after every reservation change).
 */
class Package extends Model implements Payable
{
    use HasExtras, HasPayments, SafeMorphHelper, SoftDeletes;

    protected $fillable = [
        'account_id', 'member_id', 'name', 'date_from', 'date_to', 'is_active',
        'discount_percentage', 'total_amount', 'amount', 'after_discount', 'paid_amount', 'remaining', 'notes',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'is_active' => 'boolean',
        'discount_percentage' => 'float',
        'total_amount' => 'float',
        'amount' => 'float',
        'after_discount' => 'float',
        'paid_amount' => 'float',
        'remaining' => 'float',
        // taxes (Phase C, PaymentModule Services/Tax/PackageTax): NULL = none
        'invoice_requested' => 'boolean',
        'tax_groups' => 'array',
        'tax_net' => 'float',
        'table_tax' => 'float',
        'vat' => 'float',
        'tax_extra_discount' => 'float',
        'tax_total' => 'float',
    ];

    // what the package part costs: tax_total while an invoice is requested and taxed (Phase C), else the net after discount;
    // DUE_SQL = the same in SQL (its extras are added apart: COALESCE(extras.tax_total, extras.amount))
    const DUE_SQL = 'CASE WHEN packages.invoice_requested = 1 AND packages.tax_total IS NOT NULL THEN packages.tax_total ELSE packages.after_discount END';

    public function isTaxed(): bool
    {
        return $this->invoice_requested && $this->tax_total !== null;
    }

    public function dueAmount(): float
    {
        return $this->isTaxed() ? (float) $this->tax_total : (float) $this->after_discount;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    // who added the package (users)
    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // Payable: the package net (after its discount; with its taxes when invoiced: dueAmount()) + its extras (sales — with
    // their taxes when invoiced — and balance adjustments, see HasExtras)
    public function payableAmount(): float
    {
        return round($this->dueAmount() + $this->extrasTotal(), 2);
    }

    public function payableMemberId(): int
    {
        return (int) $this->member_id;
    }

    public function canTakePayments(): bool
    {
        return true;
    }

    // [discount_percentage, after_discount] from the amount and the one typed ($type: percentage | after)
    public static function calculate(float $amount, ?float $percentage, ?float $afterDiscount, string $type = 'percentage'): array
    {
        $amount = max(0, $amount);
        if ($type === 'after') {
            $afterDiscount = min(max(0, (float) $afterDiscount), $amount);
            $percentage = $amount > 0 ? ($amount - $afterDiscount) / $amount * 100 : 0;
        } else {
            $percentage = min(max(0, (float) $percentage), 100);
            $afterDiscount = $amount - $amount * $percentage / 100;
        }

        return [round($percentage, 2), round($afterDiscount, 2)];
    }

    // total_amount / after_discount from its reservations (after a reservation is saved / deleted)
    // the package price = the net amounts of its reservations, then its discount % => the package net (after_discount)
    // (total_amount = the same sum); then paid_amount / remaining from its payments (PaymentService::refresh())
    public function recalculate(): void
    {
        $sum = $this->reservationsTotal();
        [, $afterDiscount] = self::calculate($sum, (float) $this->discount_percentage, null, 'percentage');

        // the dates: the first start / the last end of its counted reservations (no end when one of them has none: fallback only)
        $counted = $this->reservations()->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds());
        $this->date_from = (clone $counted)->min('start_at');
        $this->date_to = (clone $counted)->whereNull('end_at')->exists()
            ? null
            : (clone $counted)->max('end_at');

        $this->amount = $sum;
        $this->total_amount = $sum;
        $this->after_discount = $afterDiscount;
        // the taxes (invoice requested: per tax group, the discount before tax), then the extras follow its main group
        app(PackageTax::class)->fill($this, Account::settingsOf($this->account_id));
        $this->save();
        app(ExtraService::class)->retax($this, false);

        app(PaymentService::class)->refresh($this);
    }

    // the total hours of its reservations (start -> end, a whole day = 24 hours);
    // the continuous ones have no end: not counted, their number is returned as 'open'
    public function reservationsHours(): array
    {
        $minutes = 0;
        $open = 0;
        foreach ($this->reservations as $reservation) {
            // a not counted status (cancelled ...) is not in the hours
            if ($reservation->status && !$reservation->status->is_counted) {
                continue;
            }
            if (!$reservation->start_at || !$reservation->end_at) {
                $open++;
                continue;
            }
            // a whole day ends at 23:59:59: count the last second as a full minute
            $minutes += (int) ceil($reservation->start_at->diffInSeconds($reservation->end_at, false) / 60);
        }

        return ['hours' => round(max(0, $minutes) / 60, 2), 'open' => $open];
    }

    // the price now: the net amounts of its counted reservations (not the cancelled like statuses), 0 for a new package
    public function reservationsTotal(): float
    {
        return $this->exists
            ? round((float) $this->reservations()->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds())->sum('net_amount'), 2)
            : 0;
    }

    // from its reservations: "01-10-2026 → 31-12-2026" / "From 01-10-2026 (continues)" / "-" (no reservations)
    public function getPeriodAttribute(): string
    {
        if (!$this->date_from) {
            return '-';
        }
        $format = Reservation::DATE_FORMAT;
        return $this->date_to
            ? $this->date_from->format($format) . ' → ' . $this->date_to->format($format)
            : 'From ' . $this->date_from->format($format) . ' (continues)';
    }

    // the list tabs (docs/reservations-list-plan.md, packages list): current (default) | past | all
    const TABS = [
        'current' => 'Current',
        'past' => 'Past',
        'all' => 'All',
    ];

    // the list date field of each tab (layoutmodule::partials.date-range-filter): Current / Past keep their own rule (label only)
    public static function tabDates(): array
    {
        return [
            'current' => ['label' => 'From ' . today()->format(Reservation::DATE_FORMAT)],
            'past' => ['label' => 'Until ' . today()->subDay()->format(Reservation::DATE_FORMAT)],
        ];
    }

    // current = its period includes today or later (no end yet / no reservations yet too); past = it ended before today
    public function scopeTab($query, ?string $tab)
    {
        return match ($tab) {
            'current' => $query->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', today())),
            'past' => $query->where('date_to', '<', today()),
            default => $query,
        };
    }

    /**
     * + counted_reservations (its counted reservations: not cancelled like) and booked_minutes (end - start of the counted
     * TIMED ones: not a full day / contract subscription type, end after start) — the list "Reservations" column.
     */
    public function scopeWithReservationStats($query)
    {
        $notCounted = implode(',', array_map('intval', ReservationStatus::notCountedIds()) ?: [0]);
        $noHours = implode(',', SubscriptionType::where('booking_mode', 'full_day')->orWhere('is_contract', true)->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0]);
        $counted = "FROM reservations r WHERE r.package_id = packages.id AND r.deleted_at IS NULL AND r.reservation_status_id NOT IN ($notCounted)";

        return $query->selectRaw("(SELECT COUNT(*) $counted) AS counted_reservations")
            ->selectRaw("(SELECT COALESCE(SUM(CEIL(TIMESTAMPDIFF(SECOND, r.start_at, r.end_at) / 60)), 0) $counted"
                . " AND r.subscription_type_id NOT IN ($noHours) AND r.end_at > r.start_at) AS booked_minutes");
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', $search)
                    ->orWhereHas('member', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', str_replace(' ', '', $search)));
            });
        }

        if (!empty($filters['member_id'])) {
            $query->where('member_id', $filters['member_id']);
        }

        // unpaid / partial / paid / credit / nothing_due
        if (!empty($filters['payment_status'])) {
            $query->paymentStatus($filters['payment_status']);
        }

        // the period overlaps the range: from <= range end and (no end yet or end >= range start); no period = left out
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->whereNotNull('date_from');
        }
        if (!empty($filters['date_to'])) {
            $query->where('date_from', '<=', $filters['date_to']);
        }
        if (!empty($filters['date_from'])) {
            $query->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $filters['date_from']));
        }

        return $query;
    }
}
