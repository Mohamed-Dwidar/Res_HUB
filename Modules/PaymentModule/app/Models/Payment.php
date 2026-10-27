<?php

namespace Modules\PaymentModule\app\Models;

use Modules\PaymentModule\app\Models\Concerns\LateEntry;
use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;
use Modules\PackageModule\app\Models\Package;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\UserModule\app\Models\User;

/**
 * A payment of a reservation / a package (payable), or other income (no payable, income_category_id).
 * amount is always positive, type gives the sign: payment / withholding add, refund subtracts.
 */
class Payment extends Model
{
    use SafeMorphHelper, SoftDeletes, LateEntry;

    // the date checked by "Late entry" (Concerns\LateEntry)
    const LATE_DATE = 'paid_at';

    // the UI names (2026-10-14): money in = "Cash In" (+ withholding), money out = refunds (and expenses: "Cash Out")
    const TYPES = [
        'payment' => 'Cash In',
        'refund' => 'Refund',
        'withholding' => 'Withholding',
    ];

    // the list filter "for": the payable models (+ income = no payable)
    const SOURCES = [
        'reservation' => Reservation::class,
        'package' => Package::class,
    ];

    // the list tabs (docs/payments-plan.md "Payments list"): today (default) | this month | all, on paid_at
    const TABS = [
        'today' => 'Today',
        'month' => 'This Month',
        'all' => 'All',
    ];

    // the list date field of each tab (layoutmodule::partials.date-range-filter): the days each tab shows ("from,to"; All = empty)
    public static function tabDates(): array
    {
        return [
            'today' => ['select' => today()->toDateString()],
            'month' => ['select' => today()->startOfMonth()->toDateString() . ',' . today()->endOfMonth()->toDateString()],
        ];
    }

    // the signed amount in SQL: payments + withholding - refunds (paid totals)
    const PAID_SQL = "IF(type = 'refund', -amount, amount)";

    protected $fillable = [
        'account_id', 'payment_no', 'payable_type', 'payable_id', 'income_category_id', 'member_id',
        'type', 'amount', 'payment_method_id', 'withholding_certificate_id', 'refund_of', 'paid_at', 'reference', 'notes', 'reason',
        'delete_reason', 'deleted_by',
    ];

    protected $casts = [
        'payment_no' => 'integer',
        'amount' => 'float',
        'paid_at' => 'date',
        'shift_closing_id' => 'integer',
        'refund_of' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // the reservation / package paid (null for other income)
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function incomeCategory(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class)->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    public function withholdingCertificate(): BelongsTo
    {
        return $this->belongsTo(WithholdingCertificate::class);
    }

    // the collector's shift closing (not fillable: set by ShiftClosingService only; 0 = the collector's open shift)
    public function shiftClosing(): BelongsTo
    {
        return $this->belongsTo(ShiftClosing::class);
    }

    // who added the payment (users: the account or an employee)
    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // a refund: the payment it gives money back for (0 = an imported refund with no link)
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of')->withTrashed();
    }

    // a payment: its refunds (refund_of)
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // + for payments / withholding, - for refunds
    public function getSignedAmountAttribute(): float
    {
        return $this->type === 'refund' ? -$this->amount : $this->amount;
    }

    // reservation | package (the SOURCES key of payable_type), null for other income
    public function getPayableKindAttribute(): ?string
    {
        return array_search($this->payable_type, self::SOURCES, true) ?: null;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    // the type badge (theme palette, custom.css badge-status-*)
    public function getTypeBadgeClassAttribute(): string
    {
        return ['payment' => 'badge-status-success', 'refund' => 'badge-status-danger', 'withholding' => 'badge-status-info'][$this->type] ?? 'badge-status-slate';
    }

    // paid in total by the payments of a query: payments + withholding - refunds
    public static function paidTotal($query): float
    {
        return round((float) (clone $query)->sum(DB::raw(self::PAID_SQL)), 2);
    }

    // the payments added by one login (an employee sees only theirs in lists / totals, docs/payments-plan.md §2b)
    public function scopeCollectedBy($query, $userId)
    {
        return $query->where('creatable_type', (new User)->getMorphClass())->where('creatable_id', $userId);
    }

    // other income (no reservation / package)
    public function scopeIncome($query)
    {
        return $query->whereNull('payable_type');
    }

    public function scopeTab($query, ?string $tab)
    {
        return match ($tab) {
            'today' => $query->where('paid_at', today()->toDateString()),
            'month' => $query->whereBetween('paid_at', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()]),
            default => $query,
        };
    }

    /**
     * The list filters: search, for, method, dates, direction (in = no refunds) (+ the older keys source / type / payment_method_id /
     * income_category_id).
     * for = reservation | package | income (all other income) | category-{id} (one income category);
     * method = a payment method id | refund | withholding (the two types without their own method).
     */
    public function scopeFilter($query, array $filters = [])
    {
        // the login who recorded it (the Payments page "Created By", the account only, 2026-10-28)
        if (!empty($filters['created_by'])) {
            $query->collectedBy((int) $filters['created_by']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($query) use ($search) {
                $query->where('payment_no', 'like', $search)
                    ->orWhere('reference', 'like', $search)
                    ->orWhereHas('member', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', str_replace(' ', '', $search)));
            });
        }

        // what is paid: reservation | package | income (other income)
        if (($filters['source'] ?? '') === 'income') {
            $query->income();
        } elseif (isset(self::SOURCES[$filters['source'] ?? ''])) {
            $query->where('payable_type', (new (self::SOURCES[$filters['source']]))->getMorphClass());
        }

        $for = (string) ($filters['for'] ?? '');
        if (str_starts_with($for, 'category-')) {
            $filters['income_category_id'] = (int) substr($for, 9);
            $query->income();
        } elseif ($for === 'income') {
            $query->income();
        } elseif (isset(self::SOURCES[$for])) {
            $query->where('payable_type', (new (self::SOURCES[$for]))->getMorphClass());
        }

        // the Cash In tab (2026-10-14): no refunds (they are Cash Out)
        if (($filters['direction'] ?? '') === 'in') {
            $query->where('type', '!=', 'refund');
        }

        $method = (string) ($filters['method'] ?? '');
        if (in_array($method, ['refund', 'withholding'], true)) {
            $filters['type'] = $method;
        } elseif (ctype_digit($method)) {
            $filters['payment_method_id'] = (int) $method;
        }

        foreach (['type', 'payment_method_id', 'income_category_id', 'member_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('paid_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('paid_at', '<=', $filters['date_to']);
        }

        return $query;
    }
}
