<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// an account's bonus / deduction type (HR › Adjustment Types, owner): kind bonus (adds) | deduction (takes)
class AdjustmentType extends Model
{
    use LocalizedHelper, SoftDeletes;

    const KINDS = ['bonus' => 'Bonus', 'deduction' => 'Deduction'];

    protected array $localizedFields = ['name'];

    protected $fillable = ['account_id', 'name_en', 'name_ar', 'kind', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    // rows using it (adjustments_count = the delete check)
    public function adjustments(): HasMany
    {
        return $this->hasMany(EmployeeAdjustment::class);
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
