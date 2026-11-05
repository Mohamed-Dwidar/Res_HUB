<?php

namespace Modules\EmployeeModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Services\RoleService;
use Modules\UserModule\app\Contracts\Userable;
use Modules\UserModule\app\Models\User;

/**
 * A person of an account (docs/hr-plan.md, user 2026-11-05): the employees row IS the HR file (HrModule's Staff model = the
 * same row + its HR fields). NOT every employee can log in: a login = a users row (morph "userable") — cleaners, the owner's
 * own file … have none. Any code that lists / counts LOGINS must use Employee::withLogin(). The account manages the profile.
 */
class Employee extends Model implements Userable
{
    use SoftDeletes;

    private ?array $permissionList = null;

    const GENDERS = [
        'male' => 'Male',
        'female' => 'Female',
    ];

    protected $fillable = [
        'account_id', 'name', 'position_id', 'shift_id', 'role_id', 'phone', 'another_phone', 'gender', 'birth_date', 'address', 'image',
        'salary', 'educational_qualification', 'join_date', 'leave_date',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'join_date' => 'date',
        'leave_date' => 'date',
        'salary' => 'float',
    ];

    // the employee login (email, password)
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'userable');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(EmployeePosition::class, 'position_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(EmployeeShift::class, 'shift_id');
    }

    // his role (docs/roles-plan.md; 0 / a deleted role = the account's default role: RoleService::roleOf())
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // his permission keys, read once per request (the Gate asks many times)
    public function permissions(): array
    {
        return $this->permissionList ??= app(RoleService::class)->permissionsOf($this);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('uploads/employees/' . $this->id . '/' . $this->image) : null;
    }

    public function getGenderLabelAttribute(): string
    {
        return self::GENDERS[$this->gender] ?? '-';
    }

    // left = has a leave date that has come
    public function hasLeft(): bool
    {
        return $this->leave_date !== null && $this->leave_date->lte(today());
    }

    // only the people who have a login (a users row); employees without one are HR files only
    public function scopeWithLogin($query)
    {
        return $query->whereHas('user');
    }

    public function hasLogin(): bool
    {
        return $this->relationLoaded('user') ? $this->user !== null : $this->user()->exists();
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', $search)
                    ->orWhere('phone', 'like', str_replace(' ', '', $search))
                    ->orWhere('another_phone', 'like', str_replace(' ', '', $search))
                    ->orWhereHas('user', fn ($query) => $query->where('email', 'like', $search));
            });
        }

        if (!empty($filters['position_id'])) {
            $query->where('position_id', $filters['position_id']);
        }

        if (!empty($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }

        if (($filters['status'] ?? '') === 'working') {
            $query->where(fn ($query) => $query->whereNull('leave_date')->orWhereDate('leave_date', '>', today()));
        } elseif (($filters['status'] ?? '') === 'left') {
            $query->whereDate('leave_date', '<=', today());
        }

        return $query;
    }

    // Userable: the employee can log in while his company account is active and he has not left
    public function loginError(): ?string
    {
        if (!$this->account || $this->account->status !== Account::STATUS_ACTIVE) {
            return 'Your company account is not active, please contact your company.';
        }
        if ($this->hasLeft()) {
            return 'Your account is closed, please contact your company.';
        }
        return null;
    }

    // the Schedule page (2026-10-14), else the first page his role opens (R0, RoleService::homeRoute()); the old dashboard is
    // their profile (header menu "My Profile")
    public function homeRoute(): string
    {
        return app(RoleService::class)->homeRoute($this);
    }

    public function layout(): string
    {
        return 'layoutmodule::employee.main';
    }

    public function displayName(): string
    {
        return $this->name;
    }

    // the email is managed by the account, the employee changes his password only
    public function canChangeEmail(): bool
    {
        return false;
    }

    // he works on his account's data (reservations, packages, ...)
    public function ownerAccountId(): int
    {
        return $this->account_id;
    }

    public function area(): string
    {
        return 'employee';
    }

    // minutes without a request before he is logged out (EmployeeIdleTimeout); null = no limit (his role's idle_hours × 60,
    // docs/roles-plan.md; Front desk = none, as before R0)
    public function idleMinutes(): ?int
    {
        $hours = app(RoleService::class)->roleOf($this)?->idle_hours;
        return $hours ? $hours * 60 : null;
    }
}
