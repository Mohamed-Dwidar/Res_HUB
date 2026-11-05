<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An employee document (table employee_documents): type, name (an "Other" document's own name), number (not for the National ID: it lives in the profile),
 * dates, notes, and its files (EmployeeDocumentFile, one or more, private disk).
 * Expiry state (state()): expired / expiring (within the type's remind_days) / valid / none (no expiry date). Only the newest
 * document of a type counts for the warnings (an expired old National ID next to a new one doesn't warn: StaffService).
 */
class EmployeeDocument extends Model
{
    use SafeMorphHelper, SoftDeletes;

    protected $fillable = ['account_id', 'employee_id', 'document_type_id', 'name', 'number', 'issue_date', 'expiry_date', 'notes'];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id')->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function files(): HasMany
    {
        return $this->hasMany(EmployeeDocumentFile::class)->orderBy('id');
    }

    // what the lists show: an "Other" document's own name, else its type
    public function getLabelAttribute(): string
    {
        return $this->type?->isOther() && filled($this->name) ? $this->name : ($this->type?->name ?? 'Document');
    }

    // expired | expiring | valid | null (no expiry date)
    public function state(?Carbon $today = null): ?string
    {
        if (!$this->expiry_date) {
            return null;
        }
        $today ??= today();
        if ($this->expiry_date->lt($today)) {
            return 'expired';
        }
        return $this->expiry_date->lte($today->copy()->addDays((int) ($this->type?->remind_days ?? 30))) ? 'expiring' : 'valid';
    }

    // days until the expiry date (negative = days since)
    public function daysLeft(?Carbon $today = null): ?int
    {
        return $this->expiry_date ? (int) ($today ?? today())->diffInDays($this->expiry_date, false) : null;
    }
}
