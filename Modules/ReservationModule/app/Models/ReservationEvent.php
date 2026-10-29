<?php

namespace Modules\ReservationModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An owner override on a reservation (2026-10-29, a log; Payment Exceptions › Unpaid Renewals / Paid Cancelled):
 * renewed_unpaid = renewed before the previous period was paid (reservation = the new period, related = the unpaid one, amount =
 * what it still owed, data: new_amount, total); cancelled_paid = a reservation with payments set to a not counted status
 * (amount = its net paid, data: from / to status names). reason = the owner's reason, creatable = who.
 */
class ReservationEvent extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    const TYPES = ['renewed_unpaid' => 'Renewed before paid', 'cancelled_paid' => 'Cancelled with payments'];

    protected $fillable = ['account_id', 'reservation_id', 'related_reservation_id', 'type', 'reason', 'amount', 'data', 'creatable_type', 'creatable_id'];

    protected $casts = [
        'amount' => 'float',
        'data' => 'array',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class)->withTrashed();
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'related_reservation_id')->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }
}
