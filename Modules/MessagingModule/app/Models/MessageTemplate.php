<?php

namespace Modules\MessagingModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\SpaceModule\app\Models\Space;

/**
 * A message text of an account: type (config messagingmodule.types) + space_id (0 = the default of all spaces; a space = used
 * instead of the default for that space's reservations, is_enabled 0 there = the type is off for that space).
 * body_en empty = Arabic is used. api_*: the future WhatsApp API template, unused by click-to-chat.
 */
class MessageTemplate extends Model
{
    use SafeMorphHelper;

    protected $fillable = ['account_id', 'channel', 'type', 'space_id', 'is_enabled', 'body_ar', 'body_en'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'space_id' => 'integer',
        'api_variables' => 'array',
    ];

    // still shows the name when the space was deleted later
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class)->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDefault(): bool
    {
        return $this->space_id === 0;
    }

    public function getTypeLabelAttribute(): string
    {
        return config('messagingmodule.types.' . $this->type . '.label', $this->type);
    }
}
