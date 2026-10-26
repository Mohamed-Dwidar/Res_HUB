<?php

namespace Modules\PaymentModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\UserModule\app\Models\User;

// one step in a shift's history: its closing, a payment changed after it (payment_*: payment_id + changes before / after),
// or an employee's reminder answer (shift_closing_id 0 = the open shift)
class ShiftEvent extends Model
{
    const ACTIONS = [
        'close' => 'Closed',
        'close_on_behalf' => 'Closed by the account',
        'logout_closed' => 'Closed at logout',
        'logout_still_working' => 'Logged out, still working',
        'still_working' => 'Still working',
        'payment_edit' => 'Payment edited',
        'payment_delete' => 'Payment deleted',
        'payment_refund' => 'Payment refunded',
        'expense_edit' => 'Expense edited',
        'expense_delete' => 'Expense deleted',
        // the account reopened the closing (reason; its rows went back to the open shift, the closing row was deleted)
        'reopen' => 'Reopened by the account',
    ];

    // only created_at (a log row is never changed)
    const UPDATED_AT = null;

    protected $fillable = ['account_id', 'user_id', 'shift_closing_id', 'payment_id', 'expense_id', 'by_user_id', 'action', 'reason', 'totals', 'changes'];

    protected $casts = [
        'totals' => 'array',
        // payment_*: {before: {...}, after: {...}} (a refund: after = the refund row)
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function shiftClosing(): BelongsTo
    {
        return $this->belongsTo(ShiftClosing::class);
    }

    // payment_*: the payment changed (the deleted ones too)
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    // the collector whose shift it is
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // who did it (the employee or the account)
    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
