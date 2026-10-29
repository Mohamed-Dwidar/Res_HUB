<?php

namespace Modules\MessagingModule\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settings › WhatsApp Messages of an account (one row per channel, created on the first save; MessagingService::settings()
 * gives the defaults until then). driver: click_to_chat (a wa.me link) | api (later).
 */
class MessageSetting extends Model
{
    const DRIVERS = ['click_to_chat' => 'Click to chat (WhatsApp opens, the employee presses Send)'];

    protected $fillable = ['account_id', 'channel', 'is_enabled', 'driver', 'default_language', 'deposit_percent', 'payment_details'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'deposit_percent' => 'float',
    ];
}
