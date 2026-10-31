<?php

namespace Modules\MessagingModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\UnitModule\app\Models\Unit;

/**
 * A message of an account: name + texts (body_en empty = Arabic is used) + on / off + optional suggest_when (config
 * messagingmodule.suggest_when, NULL = Never), used for the units it is assigned to (message_template_unit; a unit has at most
 * one message per suggest_when value, MessagingService checks it).
 * api_*: the future WhatsApp API template, unused by click-to-chat.
 */
class MessageTemplate extends Model
{
    use SafeMorphHelper;

    protected $fillable = ['account_id', 'channel', 'name', 'suggest_when', 'is_enabled', 'body_ar', 'body_en'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'api_variables' => 'array',
    ];

    // the units it is sent for (deleted units left out)
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'message_template_unit', 'message_template_id', 'unit_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // "Never" for none
    public function getSuggestLabelAttribute(): string
    {
        return config('messagingmodule.suggest_when.' . $this->suggest_when . '.label', 'Never');
    }
}
