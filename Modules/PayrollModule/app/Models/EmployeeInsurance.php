<?php

namespace Modules\PayrollModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HrModule\app\Models\Staff;

// a person's social insurance registration (one row, made on the first save; the history = employee_events)
class EmployeeInsurance extends Model
{
    protected $table = 'employee_insurance';

    protected $fillable = ['account_id', 'employee_id', 'is_insured', 'insurance_no', 'start_date', 'insured_wage', 'end_date', 'notes'];

    protected $casts = ['is_insured' => 'boolean', 'start_date' => 'date', 'end_date' => 'date', 'insured_wage' => 'float'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'employee_id');
    }
}
