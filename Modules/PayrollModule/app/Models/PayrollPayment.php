<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\PaymentMethod;

// a salary payment of a locked payroll line = a company Cash Out "Salaries" (expense_id); undo = cancelled_* + the Cash Out voided
class PayrollPayment extends Model
{
    use SafeMorphHelper;

    protected $fillable = ['account_id', 'run_id', 'line_id', 'employee_id', 'amount', 'payment_method_id', 'paid_on', 'expense_id', 'cancelled_at', 'cancelled_by', 'cancel_reason'];

    protected $casts = ['amount' => 'float', 'paid_on' => 'date', 'cancelled_at' => 'datetime', 'expense_id' => 'integer'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'line_id');
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
}
