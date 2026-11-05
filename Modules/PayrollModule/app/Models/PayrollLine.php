<?php

namespace Modules\PayrollModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HrModule\app\Models\Staff;

/**
 * One person in a month's payroll (snapshot = name, no., job title, insurance no. at calculation time). net = gross + bonuses −
 * deductions − unpaid_leave − advance_installment − insurance_employee − income_tax. remaining = net − paid_amount.
 */
class PayrollLine extends Model
{
    const MONEY = ['basic', 'allowances', 'gross', 'bonuses', 'deductions', 'unpaid_leave', 'advance_installment', 'insurance_employee', 'insurance_company', 'income_tax', 'net', 'paid_amount'];

    protected $fillable = [
        'account_id', 'run_id', 'employee_id', 'snapshot', 'days_in_service', 'month_days', 'basic', 'allowances', 'gross', 'bonuses',
        'deductions', 'unpaid_leave_days', 'unpaid_leave', 'advance_installment', 'insurance_employee', 'insurance_company', 'income_tax',
        'net', 'paid_amount', 'warnings', 'notes',
    ];

    protected $casts = [
        'snapshot' => 'array', 'warnings' => 'array', 'days_in_service' => 'float', 'month_days' => 'integer', 'unpaid_leave_days' => 'float',
        'basic' => 'float', 'allowances' => 'float', 'gross' => 'float', 'bonuses' => 'float', 'deductions' => 'float', 'unpaid_leave' => 'float',
        'advance_installment' => 'float', 'insurance_employee' => 'float', 'insurance_company' => 'float', 'income_tax' => 'float',
        'net' => 'float', 'paid_amount' => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollLineItem::class, 'line_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class, 'line_id');
    }

    public function getRemainingAttribute(): float
    {
        return round($this->net - $this->paid_amount, 2);
    }

    public function getNameAttribute(): string
    {
        return $this->snapshot['name'] ?? $this->staff?->name ?? '#' . $this->employee_id;
    }
}
