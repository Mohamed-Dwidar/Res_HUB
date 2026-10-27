<?php

namespace Modules\PaymentModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A change of "Invoice requested" (docs/payments-plan.md §3 Controls): the row (loggable: reservation; package / other-income
 * payment in Phase C), before → after, the amount paid on it at that moment (> 0 = Payment Exceptions › Invoice Toggled), who.
 */
class InvoiceRequestLog extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    protected $fillable = ['account_id', 'loggable_type', 'loggable_id', 'from_value', 'to_value', 'paid_amount', 'creatable_type', 'creatable_id'];

    protected $casts = [
        'from_value' => 'boolean',
        'to_value' => 'boolean',
        'paid_amount' => 'float',
    ];

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }
}
