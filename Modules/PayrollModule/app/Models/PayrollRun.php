<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A month's payroll (docs/hr-plan.md §3.6, §6): draft (Calculate / Recalculate, reads only) | locked (frozen, payable).
 * totals = sums of the lines; history = [{type, at, by, reason, totals}].
 */
class PayrollRun extends Model
{
    use SafeMorphHelper;

    protected $fillable = ['account_id', 'period', 'status', 'totals', 'calculated_at', 'locked_at', 'locked_by', 'reopen_count', 'history', 'notes'];

    protected $casts = ['period' => 'date', 'totals' => 'array', 'history' => 'array', 'calculated_at' => 'datetime', 'locked_at' => 'datetime', 'locked_by' => 'integer', 'reopen_count' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'run_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class, 'run_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }
}
