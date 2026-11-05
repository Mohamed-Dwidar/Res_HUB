<?php

namespace Modules\EmployeeModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;

// employee shifts list of an account (the employee form selects one)
class EmployeeShift extends Model
{
    use SoftDeletes;

    protected $table = 'employee_shifts';

    protected $fillable = ['account_id', 'name', 'start_time', 'end_time', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'shift_id');
    }

    // "09:00" (the time inputs and the lists, the column is saved as 09:00:00)
    public function getStartAttribute(): ?string
    {
        return $this->start_time ? substr($this->start_time, 0, 5) : null;
    }

    public function getEndAttribute(): ?string
    {
        return $this->end_time ? substr($this->end_time, 0, 5) : null;
    }

    // "09:00 - 17:00"
    public function getTimeRangeAttribute(): string
    {
        return ($this->start ?? '?') . ' - ' . ($this->end ?? '?');
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query;
    }
}
