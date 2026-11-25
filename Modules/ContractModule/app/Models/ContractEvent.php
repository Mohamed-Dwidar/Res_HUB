<?php

namespace Modules\ContractModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A contract's history (Phase 3, 2026-11-25; ContractService::event()): issued, signed, back to draft, ended, cancelled — who, when,
 * the date it applies to (event_date), the reason, data. Written once, never edited.
 */
class ContractEvent extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    const TYPES = ['issued' => 'Issued', 'signed' => 'Signed', 'back_to_draft' => 'Back to draft', 'ended' => 'Ended', 'terminated' => 'Terminated (left)', 'restored' => 'Restored (undo left)', 'cancelled' => 'Cancelled'];

    protected $fillable = ['account_id', 'contract_id', 'type', 'event_date', 'reason', 'data'];

    protected $casts = [
        'event_date' => 'date',
        'data' => 'array',
    ];

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
