<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\HrModule\app\Models\Staff;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\PaymentMethod;

/**
 * An advance (سلفة, docs/hr-plan.md §3.5): given = a company Cash Out (expense_id); repaid by installment_amount per month from
 * first_period (+ moves: deduct (payroll H5) | repay (cash) | change (new installment); 'extra' unused since 2026-11-05). Remaining =
 * amount − Σ deduct − Σ repay (AdvanceService::remaining()). status open | settled | cancelled.
 */
class EmployeeAdvance extends Model
{
    use SafeMorphHelper;

    const STATUSES = ['open' => 'Open', 'settled' => 'Settled', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'account_id', 'employee_id', 'advance_no', 'amount', 'given_on', 'installments', 'installment_amount', 'first_period',
        'payment_method_id', 'expense_id', 'reason', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'float', 'installment_amount' => 'float', 'given_on' => 'date', 'first_period' => 'date', 'cancelled_at' => 'datetime',
        'installments' => 'integer', 'advance_no' => 'integer', 'expense_id' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }

    // every move, the cancelled ones too (withTrashed: shown greyed)
    public function moves(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceMove::class, 'advance_id')->withTrashed();
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
