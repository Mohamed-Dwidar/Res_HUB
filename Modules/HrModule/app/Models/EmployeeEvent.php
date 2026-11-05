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
        // PayrollModule (H4)
        'advance_given' => 'Advance given',
        'advance_cancelled' => 'Advance cancelled',
        'advance_repaid' => 'Advance repaid (cash)',
        'advance_extra' => 'Advance extra deduction',
        'advance_changed' => 'Advance installment changed',
        'advance_move_cancelled' => 'Advance move cancelled',
        'adjustment_added' => 'Bonus / deduction added',
        'adjustment_cancelled' => 'Bonus / deduction cancelled',
        // leaves (H3)
        'leave_added' => 'Leave added',
        'leave_cancelled' => 'Leave cancelled',
        'leave_balance_changed' => 'Leave balance changed',
        // payroll (H5)
        'salary_paid' => 'Salary paid',
        'salary_payment_undone' => 'Salary payment undone',
    ];

    // their changes / reason only with hr.salary (the History of the Profile tab)
    const PRIVATE_TYPES = ['salary_added', 'salary_edited', 'salary_deleted', 'insurance_changed', 'advance_given', 'advance_cancelled',
        'advance_repaid', 'advance_extra', 'advance_changed', 'advance_move_cancelled', 'adjustment_added', 'adjustment_cancelled',
        'salary_paid', 'salary_payment_undone'];

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
