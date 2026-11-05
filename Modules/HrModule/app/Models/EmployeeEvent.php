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
        // PayrollModule (H2): the details are shown only with hr.salary (PRIVATE_TYPES)
        'salary_added' => 'Salary added',
        'salary_edited' => 'Salary edited',
        'salary_deleted' => 'Salary deleted',
        'insurance_changed' => 'Insurance changed',
    ];

    // their changes / reason only with hr.salary (the History of the Profile tab)
    const PRIVATE_TYPES = ['salary_added', 'salary_edited', 'salary_deleted', 'insurance_changed'];

    public function isPrivate(): bool
    {
        return in_array($this->type, self::PRIVATE_TYPES, true);
    }

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
