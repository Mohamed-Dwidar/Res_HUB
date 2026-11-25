<?php

namespace Modules\UnitModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;
use Modules\PlanModule\app\Models\Plan;
use Modules\SpaceModule\app\Models\Space;
use Modules\SpaceModule\app\Models\SubscriptionType;

class Unit extends Model
{
    use LocalizedHelper, SoftDeletes;

    // read in the active language: $model->name (see App\Helpers\LocalizedHelper)
    protected array $localizedFields = ['name', 'description', 'notes'];

    // lease_period values => labels
    const LEASE_PERIODS = [
        'hour' => 'Hour',
        'day' => 'Day',
        'week' => 'Week',
        'month' => 'Month',
        'year' => 'Year',
    ];

    protected $fillable = [
        'account_id', 'space_id', 'subscription_type_id', 'name_ar', 'name_en', 'color_id',
        'description_ar', 'description_en', 'notes_ar', 'notes_en', 'capacity', 'concurrent_usage', 'lease_period', 'is_active', 'sort_order',
        // the contracts' unit numbers (2026-11-25, ContractModule): prefix + first number
        'unit_no_prefix', 'unit_no_start',
    ];

    // the units' order in their space (sort_order, moved with ↑ / ↓ on the space page), then the name
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderByLocalized('name');
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getLeasePeriodLabelAttribute(): ?string
    {
        return self::LEASE_PERIODS[$this->lease_period] ?? null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function subscriptionType(): BelongsTo
    {
        return $this->belongsTo(SubscriptionType::class);
    }

    // many to many (pivot: plan_unit), only plans assigned to the unit's space
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_unit', 'unit_id', 'plan_id')->withTimestamps();
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(UnitImage::class);
    }
}
