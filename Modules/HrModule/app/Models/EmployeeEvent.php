<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The history of an employee's HR file (table employee_events, the Profile tab): created, updated (before / after; sensitive fields as "changed" only), status,
 * login linked / unlinked, document added / updated / deleted. Never edited. Views / downloads are in access_logs, not here.
 */
class EmployeeEvent extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    const TYPES = [
        'created' => 'File created',
        'updated' => 'Edited',
        'status' => 'Status changed',
        'access_on' => 'System access on',
        'access_off' => 'System access off',
        'access_changed' => 'System access changed',
        'document_added' => 'Document added',
        'document_updated' => 'Document edited',
        'document_deleted' => 'Document deleted',
    ];

    protected $fillable = ['account_id', 'employee_id', 'type', 'changes', 'reason', 'creatable_type', 'creatable_id'];

    protected $casts = ['changes' => 'array'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
