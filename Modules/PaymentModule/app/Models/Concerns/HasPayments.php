<?php

namespace Modules\PaymentModule\app\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\PaymentModule\app\Models\Payment;

/**
 * The payments of a Payable model + its payment status.
 * The table has paid_amount / remaining (kept by PaymentService::refresh()); remaining < 0 = member credit.
 * A model that is not always paid on its own (a reservation inside a package) adds scopePayableAlone() / scopeNotPayableAlone().
 */
trait HasPayments
{
    const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid',
        'partial' => 'Partially paid',
        'paid' => 'Paid',
        'credit' => 'Credit',
        // nothing due and nothing paid (a not counted status, a 0 amount; also every row before payments:refresh runs)
        'nothing_due' => 'Nothing due',
        // paid through something else (a reservation inside a package)
        'elsewhere' => 'In package',
    ];

    const PAYMENT_STATUS_BADGES = [
        'unpaid' => 'badge-status-danger',
        'partial' => 'badge-status-warning',
        'paid' => 'badge-status-success',
        'credit' => 'badge-status-info',
        'nothing_due' => 'badge-status-slate',
        'elsewhere' => 'badge-status-primary',
    ];

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    // its payments for the payments block of its view (oldest first)
    public function paymentsList(): Collection
    {
        return $this->payments()->with('paymentMethod', 'creatable.userable', 'offsetExpense.expenseCategory')->orderBy('paid_at')->orderBy('payment_no')->get();
    }

    // paid now, from its payments: payments + withholding - refunds (the stored paid_amount is this value after a refresh)
    public function paymentsTotal(): float
    {
        return $this->exists ? Payment::paidTotal($this->payments()) : 0;
    }

    // unpaid / partial / paid / credit / nothing_due / elsewhere (from the stored paid_amount / remaining)
    public function getPaymentStatusAttribute(): string
    {
        if (!$this->canTakePayments()) {
            return 'elsewhere';
        }
        $remaining = round((float) $this->remaining, 2);
        $paid = round((float) $this->paid_amount, 2);
        if ($remaining < 0) {
            return 'credit';
        }
        if ($remaining == 0) {
            return $paid != 0 ? 'paid' : 'nothing_due';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status];
    }

    // the payment status badge (theme palette, custom.css badge-status-*)
    public function getPaymentStatusBadgeClassAttribute(): string
    {
        return self::PAYMENT_STATUS_BADGES[$this->payment_status];
    }

    // the list filter "payment status": the same rules as getPaymentStatusAttribute() in SQL
    public function scopePaymentStatus($query, ?string $status)
    {
        if (!isset(self::PAYMENT_STATUSES[$status])) {
            return $query;
        }

        $table = $this->getTable();
        $alone = method_exists($this, 'scopePayableAlone');
        if ($status === 'elsewhere') {
            return $alone ? $query->notPayableAlone() : $query->whereRaw('0 = 1');
        }
        if ($alone) {
            $query->payableAlone();
        }

        return match ($status) {
            'credit' => $query->where("$table.remaining", '<', 0),
            'paid' => $query->where("$table.remaining", 0)->where("$table.paid_amount", '<>', 0),
            'nothing_due' => $query->where("$table.remaining", 0)->where("$table.paid_amount", 0),
            'partial' => $query->where("$table.remaining", '>', 0)->where("$table.paid_amount", '>', 0),
            'unpaid' => $query->where("$table.remaining", '>', 0)->where("$table.paid_amount", '<=', 0),
        };
    }
}
