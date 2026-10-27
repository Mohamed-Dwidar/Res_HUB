<?php

namespace Modules\PlanModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;
use Modules\PaymentModule\app\Models\Concerns\HasTaxFlags;
use Modules\SpaceModule\app\Models\Space;
use Modules\UnitModule\app\Models\Unit;

class Plan extends Model
{
    // vat_exempt, table_tax, price_includes_tax (docs/payments-plan.md §3)
    use HasTaxFlags, SoftDeletes;

    // lease_period values => labels (same as the units)
    const LEASE_PERIODS = Unit::LEASE_PERIODS;

    protected $fillable = ['account_id', 'name', 'capacity', 'lease_period', 'facilities', 'amount', 'is_time_based', 'is_active'];

    protected $casts = [
        'capacity' => 'integer',
        'amount' => 'float',
        // the reservations have a start / end time (not only dates)
        'is_time_based' => 'boolean',
        'is_active' => 'boolean',
    ];

    // the hours of one lease period (a time based plan is paid by these periods; month = 30 days, year = 365 days)
    const PERIOD_HOURS = ['hour' => 1, 'day' => 24, 'week' => 168, 'month' => 720, 'year' => 8760];

    // how many lease periods from $start to $end (e.g. 2.5 hours), the same calculation as custom.js reservationPlanAmount()
    public function periodsBetween($start, $end): float
    {
        $hours = max(0, $start->diffInMinutes($end, false)) / 60;
        return round($hours / (self::PERIOD_HOURS[$this->lease_period] ?? 1), 2);
    }

    // the amount of a time based reservation: the plan amount x the periods
    public function amountBetween($start, $end): float
    {
        return round((float) $this->amount * $this->periodsBetween($start, $end), 2);
    }

    public function getLeasePeriodLabelAttribute(): ?string
    {
        return self::LEASE_PERIODS[$this->lease_period] ?? null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // many to many (pivot: plan_space), chosen from the space form
    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'plan_space', 'plan_id', 'space_id')->withTimestamps();
    }

    // many to many (pivot: plan_unit), chosen from the unit form (only plans of the unit's space)
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'plan_unit', 'plan_id', 'unit_id')->withTimestamps();
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['space_id'])) {
            $query->whereHas('spaces', fn ($query) => $query->where('spaces.id', $filters['space_id']));
        }

        return $query;
    }
}
