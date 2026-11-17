<?php

namespace Modules\PaymentModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A discount change of a reservation / package (2026-11-17): the % / the fixed amount before → after, the amount
 * paid on it at that moment, who. Payment Exceptions › Discounts. Written by ReservationService (create with a discount typed by
 * hand / every change) and PackageService::update().
 */
class DiscountLog extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    protected $fillable = ['account_id', 'loggable_type', 'loggable_id', 'from_percentage', 'to_percentage', 'from_fixed', 'to_fixed',
        'paid_amount', 'creatable_type', 'creatable_id'];

    protected $casts = [
        'from_percentage' => 'float',
        'to_percentage' => 'float',
        'from_fixed' => 'float',
        'to_fixed' => 'float',
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

    // $from = [percentage, fixed] before; $row = the saved reservation / package
    public static function record(Model $row, array $from, ?Model $by): self
    {
        $log = new self([
            'account_id' => $row->account_id,
            'from_percentage' => (float) $from[0], 'to_percentage' => (float) $row->discount_percentage,
            'from_fixed' => (float) $from[1], 'to_fixed' => (float) $row->discount_fixed,
            'paid_amount' => (float) $row->paid_amount,
        ]);
        $log->loggable()->associate($row);
        if ($by) {
            $log->creatable()->associate($by);
        }
        $log->save();

        return $log;
    }

    // only when the % / the fixed amount changed
    public static function recordChange(Model $row, array $from, ?Model $by): void
    {
        if (round((float) $from[0], 2) != round((float) $row->discount_percentage, 2) || round((float) $from[1], 2) != round((float) $row->discount_fixed, 2)) {
            self::record($row, $from, $by);
        }
    }

    // "10%" / "−300.00" (none = "No discount")
    public static function text(float $percentage, float $fixed): string
    {
        $parts = [];
        if ($percentage > 0) {
            $parts[] = rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.') . '%';
        }
        if ($fixed > 0) {
            $parts[] = '−' . number_format($fixed, 2);
        }
        return $parts ? implode(' · ', $parts) : 'No discount';
    }
}
