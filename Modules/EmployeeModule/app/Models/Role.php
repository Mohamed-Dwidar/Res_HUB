<?php

namespace Modules\EmployeeModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What an employee may do (docs/roles-plan.md, R0 2026-11-03): an account's role = ticked permission keys
 * (config employeemodule.permissions) + idle minutes; one role per employee (employees.role_id, 0 = the default role).
 * is_default = the account's "Front desk": new employees get it, can't be deleted.
 */
class Role extends Model
{
    use SoftDeletes;

    protected $fillable = ['account_id', 'name', 'permissions', 'idle_hours', 'is_default', 'created_by'];

    protected $casts = [
        'permissions' => 'array',
        'idle_hours' => 'integer',
        'is_default' => 'boolean',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        return $query;
    }
}
