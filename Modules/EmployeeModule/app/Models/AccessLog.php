<?php

namespace Modules\EmployeeModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\UserModule\app\Models\User;

/**
 * One line of the Access Log (docs/roles-plan.md): login | login_failed | logout | timeout | export | action.
 * Written by AccessLogService only, never edited.
 */
class AccessLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['account_id', 'user_id', 'email', 'event', 'page', 'format', 'filters', 'rows', 'subject_type', 'subject_id', 'ip', 'user_agent'];

    protected $casts = [
        'filters' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getEventLabelAttribute(): string
    {
        return config('employeemodule.access_events.' . $this->event, $this->event);
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        // ranges, no whereDate (the index)
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<', date('Y-m-d', strtotime($filters['date_to'] . ' +1 day')) . ' 00:00:00');
        }

        return $query;
    }
}
