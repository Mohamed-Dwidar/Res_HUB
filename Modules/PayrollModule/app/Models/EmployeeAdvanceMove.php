<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\PaymentModule\app\Models\Payment;

/**
 * A move of an advance: deduct (payroll H5, payroll_line_id) | extra (REMOVED 2026-11-05: unused, old rows cancelled) | repay (cash:
 * payment_id = the Cash In "Advance repayment") | change (installment from period: amount = the new installment).
 * Cancelled = soft deleted (delete_reason, deleted_by).
 */
class EmployeeAdvanceMove extends Model
{
    use SafeMorphHelper, SoftDeletes;

    const TYPES = ['deduct' => 'Deducted (payroll)', 'extra' => 'Extra deduction', 'repay' => 'Cash repayment', 'change' => 'Installment changed'];

    protected $fillable = ['account_id', 'advance_id', 'type', 'amount', 'period', 'payment_id', 'reason', 'delete_reason', 'deleted_by'];

    protected $casts = ['amount' => 'float', 'period' => 'date', 'payment_id' => 'integer', 'payroll_line_id' => 'integer'];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'advance_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
