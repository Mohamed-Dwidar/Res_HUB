<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A leave HR recorded (docs/hr-plan.md §3.4; no request / approval): type, from – to, days (calendar days by default, half days),
 * notes, one optional private file (attachment = the random name on hr/{account}/{employee}/, attachment_name = the original).
 * Counted in the year of from_date. Cancelled = soft deleted (delete_reason, deleted_by).
 */
class EmployeeLeave extends Model
{
    use SafeMorphHelper, SoftDeletes;

    protected $fillable = ['account_id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'days', 'notes', 'attachment', 'attachment_name', 'delete_reason', 'deleted_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'days' => 'float'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id')->withTrashed();
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // "2 days" / "0.5 day"
    public function daysText(): string
    {
        return rtrim(rtrim(number_format($this->days, 1), '0'), '.') . ' ' . ($this->days == 1 || $this->days == 0.5 ? 'day' : 'days');
    }

    // "05-11-2026" or "05-11-2026 → 07-11-2026"
    public function datesText(): string
    {
        return $this->from_date->format('d-m-Y') . ($this->to_date->equalTo($this->from_date) ? '' : ' → ' . $this->to_date->format('d-m-Y'));
    }
}
