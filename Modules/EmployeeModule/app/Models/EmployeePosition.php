<?php

namespace Modules\EmployeeModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;

// an account's job titles (the staff form selects one); department_id = its department (0 = none; HrModule departments,
// relation department() added by HrModuleServiceProvider) — an employee's department = his job title's
class EmployeePosition extends Model
{
    use SoftDeletes;

    protected $table = 'employee_positions';

    protected $fillable = ['account_id', 'department_id', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'department_id' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query;
    }
}
