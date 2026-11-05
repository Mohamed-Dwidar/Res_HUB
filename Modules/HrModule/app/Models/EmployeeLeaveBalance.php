<?php

namespace Modules\HrModule\app\Models;

use Illuminate\Database\Eloquent\Model;

// a person's own yearly days of a leave type for one year (only when HR changed the type's default)
class EmployeeLeaveBalance extends Model
{
    protected $fillable = ['account_id', 'employee_id', 'leave_type_id', 'year', 'days'];

    protected $casts = ['year' => 'integer', 'days' => 'float'];
}
