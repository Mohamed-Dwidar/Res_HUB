<?php

namespace Modules\PayrollModule\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The account's payroll settings (HR › Payroll Settings, owner; one row, made on the first save). Insurance % NULL = not set
 * yet (a warning). month_days = the daily rate's divisor.
 */
class PayrollSetting extends Model
{
    protected $fillable = ['account_id', 'insurance_employee_percent', 'insurance_company_percent', 'month_days'];

    protected $casts = ['insurance_employee_percent' => 'float', 'insurance_company_percent' => 'float', 'month_days' => 'integer'];

    // the saved row, else the defaults (not saved)
    public static function of($accountId): self
    {
        return self::where('account_id', $accountId)->first() ?? new self(['account_id' => $accountId, 'month_days' => 30]);
    }

    public function insuranceSet(): bool
    {
        return $this->insurance_employee_percent !== null && $this->insurance_company_percent !== null;
    }
}
