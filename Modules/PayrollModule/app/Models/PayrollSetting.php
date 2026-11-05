<?php

namespace Modules\PayrollModule\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The account's payroll settings (HR › Payroll Settings, owner; one row, made on the first save). Insurance % NULL = not set
 * yet (a warning). month_days = the daily rate's divisor. advance_warn_percent = warn when a month's advance installments
 * go above this % of the salary (default 50). first_payroll_month = the first month paid through Payroll (earlier months were paid by
 * hand: Calculate / Lock / Pay refused for them; NULL = not set yet, a warning).
 */
class PayrollSetting extends Model
{
    protected $fillable = ['account_id', 'insurance_employee_percent', 'insurance_company_percent', 'month_days', 'advance_warn_percent', 'first_payroll_month'];

    protected $casts = ['insurance_employee_percent' => 'float', 'insurance_company_percent' => 'float', 'month_days' => 'integer', 'advance_warn_percent' => 'float', 'first_payroll_month' => 'date'];

    // the saved row, else the defaults (not saved)
    public static function of($accountId): self
    {
        return self::where('account_id', $accountId)->first() ?? new self(['account_id' => $accountId, 'month_days' => 30, 'advance_warn_percent' => 50]);
    }

    public function insuranceSet(): bool
    {
        return $this->insurance_employee_percent !== null && $this->insurance_company_percent !== null;
    }
}
