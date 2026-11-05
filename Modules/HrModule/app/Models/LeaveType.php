<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An account's leave type (HR › Leave Types, owner; docs/hr-plan.md §3.4): is_paid (0 = payroll deducts its days),
 * yearly_days (everyone's yearly balance, NULL = none kept; per person + year: EmployeeLeaveBalance), needs_attachment (a warning).
 */
class LeaveType extends Model
{
    use LocalizedHelper, SoftDeletes;

    protected array $localizedFields = ['name'];

    protected $fillable = ['account_id', 'name_en', 'name_ar', 'is_paid', 'yearly_days', 'needs_attachment', 'is_active', 'sort_order'];

    protected $casts = ['is_paid' => 'boolean', 'needs_attachment' => 'boolean', 'is_active' => 'boolean', 'yearly_days' => 'float'];

    // its leaves (leaves_count = the delete check, cancelled ones too)
    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class)->withTrashed();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
