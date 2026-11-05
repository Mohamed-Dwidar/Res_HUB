<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of staff document (HR › Document Types): has_expiry = the document form asks for an expiry date and the file warns
 * remind_days before it; is_required = listed as missing on every current staff file without one (a warning, never a block).
 */
class DocumentType extends Model
{
    use LocalizedHelper, SoftDeletes;

    protected array $localizedFields = ['name'];

    // kind: national_id (no Number in the document form: the ID number is in the profile) | other (a typed name) | null
    const KIND_NATIONAL_ID = 'national_id';
    const KIND_OTHER = 'other';

    protected $fillable = ['account_id', 'name_en', 'name_ar', 'kind', 'has_expiry', 'remind_days', 'is_required', 'is_active', 'sort_order'];

    protected $casts = [
        'has_expiry' => 'boolean',
        'remind_days' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function isOther(): bool
    {
        return $this->kind === self::KIND_OTHER;
    }

    public function isNationalId(): bool
    {
        return $this->kind === self::KIND_NATIONAL_ID;
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
