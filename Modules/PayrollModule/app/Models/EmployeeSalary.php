<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\HrModule\app\Models\Staff;

/**
 * One salary of a person from a date (docs/hr-plan.md §3.3): basic + its allowances (employee_salary_items). A raise = a new
 * row; the one in force on a day = the latest effective_from ≤ that day. Total = basic + Σ items; insurable = basic + Σ items
 * of insurable types.
 */
class EmployeeSalary extends Model
{
    use SafeMorphHelper, SoftDeletes;

    protected $fillable = ['account_id', 'employee_id', 'effective_from', 'basic', 'reason'];

    protected $casts = ['effective_from' => 'date', 'basic' => 'float'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeSalaryItem::class, 'salary_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getAllowancesTotalAttribute(): float
    {
        return round((float) $this->items->sum('amount'), 2);
    }

    public function getTotalAttribute(): float
    {
        return round($this->basic + $this->allowances_total, 2);
    }

    public function getInsurableAttribute(): float
    {
        return round($this->basic + (float) $this->items->filter(fn ($item) => $item->type?->is_insurable)->sum('amount'), 2);
    }

    // "Transport 300.00, Meals 200.00" (the history line)
    public function itemsText(): string
    {
        return $this->items->map(fn ($item) => ($item->type?->name ?? '#' . $item->allowance_type_id) . ' ' . number_format($item->amount, 2))->implode(', ');
    }
}
