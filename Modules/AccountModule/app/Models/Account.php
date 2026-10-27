<?php

namespace Modules\AccountModule\app\Models;

use App\Helpers\LocalizedHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Modules\CompanyModule\app\Models\Company;
use Modules\JobModule\app\Models\Job;
use Modules\MemberModule\app\Models\Member;
use Modules\SpaceModule\app\Models\Space;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UserModule\app\Contracts\Userable;
use Modules\UserModule\app\Models\User;

class Account extends Model implements Userable
{
    use LocalizedHelper, SoftDeletes;

    // read in the active language: $model->name (see App\Helpers\LocalizedHelper)
    protected array $localizedFields = ['name', 'description'];

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_REJECTED = 'rejected';

    // filter value for the soft deleted accounts list
    const FILTER_DELETED = 'deleted';

    const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INACTIVE => 'Inactive',
        self::STATUS_REJECTED => 'Rejected',
    ];

    // optional leading +, then 8-15 digits (e.g. 01012345678 or +201012345678)
    const PHONE_REGEX = '/^\+?[0-9]{8,15}$/';

    // fields the account owner fills after approval to complete his profile
    const PROFILE_FIELDS = ['name_en', 'address', 'description_ar', 'description_en', 'logo'];

    protected $fillable = [
        'name_ar', 'name_en', 'phone', 'address', 'description_ar', 'description_en', 'logo', 'status', 'approved_at',
        'withholding_enabled', 'vat_enabled', 'today_hours_from', 'today_hours_to', 'renewal_days',
        'late_entry_hours', 'expense_review_amount',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'withholding_enabled' => 'boolean',
        'vat_enabled' => 'boolean',
        'today_hours_from' => 'integer',
        'today_hours_to' => 'integer',
        'renewal_days' => 'integer',
        'late_entry_hours' => 'integer',
        'expense_review_amount' => 'decimal:2',
    ];

    // Settings › General, loaded once per request by account id (settingsOf())
    private static array $settings = [];

    // the account (its settings) by id, loaded once per request — the views / services of its employees ask it too
    public static function settingsOf($accountId): ?self
    {
        // the logged in account's own row when it is that account (no second query)
        $userable = Auth::user()?->userable;
        return self::$settings[(int) $accountId] ??= $userable instanceof self && (int) $userable->id === (int) $accountId
            ? $userable : static::find((int) $accountId);
    }

    // after Settings › General is saved: the next settingsOf() reads it again
    public static function forgetSettings($accountId): void
    {
        unset(self::$settings[(int) $accountId]);
    }

    /**
     * Withholding tax (Settings › General, default ON). OFF: no new withholding (payment type / method filter option,
     * certificates page + menu hidden); existing withholding records stay as they are and are still shown (docs/payments-plan.md).
     */
    public function withholdingEnabled(): bool
    {
        return (bool) ($this->withholding_enabled ?? true);
    }

    // VAT (Settings › General, default ON): Phase 2 only (OFF = prices tax free, no tax rates / lines; docs/payments-plan.md §3)
    public function vatEnabled(): bool
    {
        return (bool) ($this->vat_enabled ?? true);
    }

    // the account (its settings) of the logged in user (the account or its employee), null for an admin / a guest
    public static function current(): ?self
    {
        $userable = Auth::user()?->userable;
        return $userable && method_exists($userable, 'ownerAccountId') ? self::settingsOf($userable->ownerAccountId()) : null;
    }

    /**
     * The Schedule page timeline hours (Settings › General): from 0–23, to from + 1 … 30 (> 24 = the next morning, 26 = 02:00).
     * The business day of a date D = [D cutoff:00, D+1 cutoff:00), cutoff = to − 24 (0 when the day ends by midnight): a place
     * open through the night keeps its night (until the cutoff) on the day it started.
     */
    public function todayHours(): array
    {
        $from = min(23, max(0, (int) ($this->today_hours_from ?? 8)));
        return [$from, min(30, max($from + 1, (int) ($this->today_hours_to ?? 24)))];
    }

    public function cutoffHours(): int
    {
        return max(0, $this->todayHours()[1] - 24);
    }

    // the business date now (or at $at): before the cutoff it is still the day before
    public function businessDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->subHours($this->cutoffHours())->startOfDay();
    }

    // [start, end) of the business day of $date
    public function businessDay(Carbon $date): array
    {
        $start = $date->copy()->startOfDay()->addHours($this->cutoffHours());
        return [$start, $start->copy()->addDay()];
    }

    // the renewals window of the Schedule page (days after the business date)
    public function renewalDays(): int
    {
        return min(90, max(1, (int) ($this->renewal_days ?? 7)));
    }

    // Payment Controls (2026-10-27): recorded more than these hours after the end of its business date = "Late entry"
    public function lateEntryHours(): int
    {
        return min(720, max(0, (int) ($this->late_entry_hours ?? 12)));
    }

    // cash outs of employees from this amount = the Payment Exceptions report's Expenses tab
    public function expenseReviewAmount(): float
    {
        return max(0, (float) ($this->expense_review_amount ?? 500));
    }

    // the account login (email, password)
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'userable');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function subscriptionTypes(): HasMany
    {
        return $this->hasMany(SubscriptionType::class);
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (($filters['status'] ?? null) == self::FILTER_DELETED) {
            $query->onlyTrashed();
        } elseif (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($query) use ($search) {
                $query->where('name_ar', 'like', $search)
                    ->orWhere('name_en', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhereHas('user', function ($query) use ($search) {
                        $query->where('email', 'like', $search);
                    });
            });
        }

        return $query;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('uploads/accounts/' . $this->id . '/' . $this->logo) : null;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    // percentage of the profile fields that are filled
    public function profileCompletion(): int
    {
        $filled = collect(self::PROFILE_FIELDS)->filter(fn ($field) => filled($this->$field))->count();
        return (int) round($filled / count(self::PROFILE_FIELDS) * 100);
    }

    /* ---------- Userable ---------- */

    public function loginError(): ?string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => null,
            self::STATUS_PENDING => 'Your account is waiting for the admin approval.',
            self::STATUS_REJECTED => 'Your registration request has been rejected.',
            default => 'Your account is inactive, please contact the administrator.',
        };
    }

    public function homeRoute(): string
    {
        return 'account.dashboard';
    }

    public function layout(): string
    {
        return 'layoutmodule::account.main';
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function canChangeEmail(): bool
    {
        return true;
    }

    public function ownerAccountId(): int
    {
        return $this->id;
    }
}
