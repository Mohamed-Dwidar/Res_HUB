<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\HrModule\app\Models\Staff;

/**
 * A one-off bonus / deduction of a person for a month (docs/hr-plan.md §3.5): amount OR days (× the daily rate, by payroll H5).
 * Pending until payroll: H5 reads the month's rows with payroll_line_id 0. Cancelled = soft deleted (delete_reason, deleted_by).
 */
class EmployeeAdjustment extends Model
{
    use SafeMorphHelper, SoftDeletes;

    protected $fillable = ['account_id', 'employee_id', 'adjustment_type_id', 'period', 'amount', 'days', 'reason', 'delete_reason', 'deleted_by'];

    protected $casts = ['period' => 'date', 'amount' => 'float', 'days' => 'float', 'payroll_line_id' => 'integer'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AdjustmentType::class, 'adjustment_type_id')->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDeduction(): bool
    {
        return $this->type?->kind === 'deduction';
    }

    // "1,500.00" or "2 days" (days: 0.5 steps)
    public function valueText(): string
    {
        return $this->days !== null
            ? rtrim(rtrim(number_format($this->days, 2), '0'), '.') . ' ' . ($this->days == 1 ? 'day' : 'days')
            : number_format((float) $this->amount, 2);
    }
}
