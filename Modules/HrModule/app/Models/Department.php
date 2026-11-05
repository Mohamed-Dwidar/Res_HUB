<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\EmployeeModule\app\Models\EmployeePosition;
use Illuminate\Database\Eloquent\SoftDeletes;

// an account's department (HR › Employee Departments), chosen on a job title (employee_positions.department_id, 2026-11-05): an
// employee's department = his job title's
class Department extends Model
{
    use LocalizedHelper, SoftDeletes;

    protected array $localizedFields = ['name'];

    protected $fillable = ['account_id', 'name_en', 'name_ar', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    // its job titles (positions_count = the delete check)
    public function positions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class, 'department_id');
    }

    // its people = the employees whose job title is in it (staff_count = the HR › Employee Departments column)
    public function staff(): HasManyThrough
    {
        return $this->hasManyThrough(Staff::class, EmployeePosition::class, 'department_id', 'position_id');
    }
}
