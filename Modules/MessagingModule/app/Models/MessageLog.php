<?php

namespace Modules\MessagingModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message sent (a log, no updates by the app): messageable = what it was about (a reservation now), member + the
 * international number used, type / language, creatable = who. No message text. status / external_id: the WhatsApp API only.
 * Reservation::messageLogs() is added by MessagingModuleServiceProvider (the rows' icon state).
 */
class MessageLog extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    protected $fillable = ['account_id', 'channel', 'driver', 'messageable_type', 'messageable_id', 'member_id', 'type', 'language', 'phone',
        'status', 'external_id', 'creatable_type', 'creatable_id'];

    public function messageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return config('messagingmodule.types.' . $this->type . '.label', $this->type);
    }
}
