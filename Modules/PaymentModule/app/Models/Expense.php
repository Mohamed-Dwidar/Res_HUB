<?php

namespace Modules\PaymentModule\app\Models;

use Modules\PaymentModule\app\Models\Concerns\LateEntry;
use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;
use Modules\UserModule\app\Models\User;

/**
 * Money paid out from a drawer (docs/payments-plan.md "Expenses"): it joins the open shift of whoever records it
 * (shift_closing_id 0 → the shift closing), like a refund. Amount > 0 in the app (2 imported old rows are negative = returns);
 * Always a payment method (imported old method 5 "withholding notice" → Cash, user 2026-10-14).
 */
class Expense extends Model
{
    use SafeMorphHelper, SoftDeletes, LateEntry;

    // the date checked by "Late entry" (Concerns\LateEntry)
    const LATE_DATE = 'spent_at';

    // the list tabs (account): today (default) | this month | all, on spent_at — same as the payments list
    const TABS = [
        'today' => 'Today',
        'month' => 'This Month',
        'all' => 'All',
    ];

    // the method label of payment_method_id 0 (imported old method 5)
    const NO_METHOD = 'No method (withholding notice)';

    protected $fillable = [
        'account_id', 'expense_no', 'expense_category_id', 'item', 'amount', 'payment_method_id', 'reference', 'spent_at', 'notes', 'attachment',
        'delete_reason', 'deleted_by',
    ];

    protected $casts = [
        'expense_no' => 'integer',
        'amount' => 'float',
        'spent_at' => 'date',
        'shift_closing_id' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class)->withTrashed();
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    // the recorder's shift closing (not fillable: set by ShiftClosingService only; 0 = their open shift)
    public function shiftClosing(): BelongsTo
    {
        return $this->belongsTo(ShiftClosing::class);
    }

    // who recorded it (users: the account or an employee)
    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // the method name, or "No method (withholding notice)" for 0
    public function getMethodNameAttribute(): string
    {
        return $this->payment_method_id ? ($this->paymentMethod?->name ?? 'Method #' . $this->payment_method_id) : self::NO_METHOD;
    }

    // the expenses recorded by one login (an employee sees only theirs)
    public function scopeCollectedBy($query, $userId)
    {
        return $query->where('creatable_type', (new User)->getMorphClass())->where('creatable_id', $userId);
    }

    public function scopeTab($query, ?string $tab)
    {
        return match ($tab) {
            'today' => $query->where('spent_at', today()->toDateString()),
            'month' => $query->whereBetween('spent_at', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()]),
            default => $query,
        };
    }

    // the list date field of each tab (layoutmodule::partials.date-range-filter): the days each tab shows ("from,to"; All = empty)
    public static function tabDates(): array
    {
        return [
            'today' => ['select' => today()->toDateString()],
            'month' => ['select' => today()->startOfMonth()->toDateString() . ',' . today()->endOfMonth()->toDateString()],
        ];
    }

    // search (no., item, notes) | expense_category_id | method (an id) | date_from / date_to (spent_at)
    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(fn ($query) => $query->where('expense_no', 'like', $search)->orWhere('item', 'like', $search)->orWhere('notes', 'like', $search));
        }
        if (!empty($filters['expense_category_id'])) {
            $query->where('expense_category_id', (int) $filters['expense_category_id']);
        }
        $method = (string) ($filters['method'] ?? '');
        if (ctype_digit($method)) {
            $query->where('payment_method_id', (int) $method);
        }
        if (!empty($filters['date_from'])) {
            $query->where('spent_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('spent_at', '<=', $filters['date_to']);
        }

        return $query;
    }
}
