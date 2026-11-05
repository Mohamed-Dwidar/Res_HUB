<?php

namespace Modules\PayrollModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// one allowance of a salary row (employee_salary_items: salary_id, allowance_type_id, amount)
class EmployeeSalaryItem extends Model
{
    protected $fillable = ['salary_id', 'allowance_type_id', 'amount'];

    protected $casts = ['amount' => 'float'];

    public function salary(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalary::class, 'salary_id');
    }

    // a deleted type keeps its name in the old rows
    public function type(): BelongsTo
    {
        return $this->belongsTo(AllowanceType::class, 'allowance_type_id')->withTrashed();
    }
}
