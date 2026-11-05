<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\EmployeeModule\app\Models\EmployeePosition;
use Modules\EmployeeModule\app\Models\EmployeeShift;

/**
 * The HR view of an employee (docs/hr-plan.md §3.1, user 2026-11-05): the SAME `employees` row — one row per person, with or
 * without a login (user() = the users row; none = cleaners, the owner who uses the Account login). This class only adds the HR
 * fields, casts and relations, so EmployeeModule (logins, roles, the front desk) never loads HR code.
 * getMorphClass() = Employee: the login (users.userable), creatable and access_logs subjects all keep the Employee class.
 * SENSITIVE fields are shown / saved only with hr.sensitive; national_id / bank_account / wallet_number are encrypted with
 * APP_KEY (never change APP_KEY on the server, keep a backup of it: without it these values are lost).
 * is_external = not on payroll (e.g. the accounting office's login): out of payroll, headcount, HR reports / warnings.
 * Columns shared with the login: name, phone, another_phone, gender, birth_date, address, educational_qualification,
 * position_id, shift_id, join_date (= hire date), leave_date (= the login is off from that day), role_id.
 */
class Staff extends Employee
{
    use SafeMorphHelper;

    protected $table = 'employees';

    // permission hr.sensitive (StaffRequest drops them without it, the views hide them)
    const SENSITIVE = [
        'birth_date', 'nationality', 'id_type', 'national_id', 'marital_status', 'military_status', 'educational_qualification', 'address',
        'pay_method', 'bank_name', 'bank_account', 'wallet_number', 'emergency_name', 'emergency_relation', 'emergency_phone',
    ];

    // their old / new values are never written in the history (only "changed")
    const SECRET = ['national_id', 'bank_account', 'wallet_number'];

    // the HR form's fields (the login's own: role_id, leave_date are set by StaffService, never filled from a form)
    protected $fillable = [
        'account_id', 'employee_no', 'is_external', 'name', 'name_ar', 'gender', 'phone', 'another_phone', 'email',
        'position_id', 'shift_id', 'join_date', 'probation_ends_on', 'status', 'status_date', 'status_reason',
        'birth_date', 'nationality', 'id_type', 'national_id', 'marital_status', 'military_status', 'educational_qualification', 'address',
        'pay_method', 'bank_name', 'bank_account', 'wallet_number', 'emergency_name', 'emergency_relation', 'emergency_phone', 'notes',
    ];

    protected $casts = [
        'employee_no' => 'integer',
        'is_external' => 'boolean',
        'position_id' => 'integer',
        'shift_id' => 'integer',
        'role_id' => 'integer',
        'join_date' => 'date',
        'leave_date' => 'date',
        'probation_ends_on' => 'date',
        'status_date' => 'date',
        'birth_date' => 'date',
        'salary' => 'float',
        'national_id' => 'encrypted',
        'bank_account' => 'encrypted',
        'wallet_number' => 'encrypted',
    ];

    // the same row as an Employee everywhere outside HR (users.userable, creatable, access_logs subject)
    public function getMorphClass()
    {
        return Employee::class;
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(EmployeePosition::class, 'position_id')->withTrashed();
    }

    // the department = the job title's (2026-11-05: no department on the employee); eager load 'position.department'
    public function getDepartmentAttribute(): ?Department
    {
        return $this->position?->department;
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(EmployeeShift::class, 'shift_id')->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmployeeEvent::class, 'employee_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getStatusLabelAttribute(): string
    {
        return config('hrmodule.statuses.' . $this->status, $this->status);
    }

    // resigned / terminated
    public function hasEnded(): bool
    {
        return in_array($this->status, config('hrmodule.ended_statuses'), true);
    }

    // the ID number for the screen: in full with hr.manage, else the last 4 digits only
    public function nationalIdFor(bool $full): ?string
    {
        $value = $this->national_id;
        if (blank($value) || $full) {
            return $value;
        }
        return str_repeat('•', max(0, mb_strlen($value) - 4)) . mb_substr($value, -4);
    }

    public static function label(string $list, ?string $value): string
    {
        return $value ? config('hrmodule.' . $list . '.' . $value, $value) : '-';
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (filled($search = trim((string) ($filters['search'] ?? '')))) {
            $like = '%' . $search . '%';
            $query->where(function ($query) use ($like, $search) {
                $query->where('employees.name', 'like', $like)->orWhere('employees.name_ar', 'like', $like)
                    ->orWhere('employees.phone', 'like', $like)->orWhere('employees.another_phone', 'like', $like);
                if (ctype_digit($search)) {
                    $query->orWhere('employees.employee_no', (int) $search);
                }
            });
        }
        if (filled($filters['position_id'] ?? null)) {
            $query->where('employees.position_id', (int) $filters['position_id']);
        }
        // the department of the job title
        if (filled($filters['department_id'] ?? null)) {
            $query->whereIn('employees.position_id', EmployeePosition::withTrashed()->where('department_id', (int) $filters['department_id'])->select('id'));
        }
        if (in_array($filters['type'] ?? '', ['staff', 'external'], true)) {
            $query->where('employees.is_external', $filters['type'] === 'external');
        }
        // '' = current (active + on leave), all, left (resigned + terminated), or one status
        $status = (string) ($filters['status'] ?? '');
        match (true) {
            $status === '' => $query->whereNotIn('employees.status', config('hrmodule.ended_statuses')),
            $status === 'all' => null,
            $status === 'left' => $query->whereIn('employees.status', config('hrmodule.ended_statuses')),
            default => $query->where('employees.status', $status),
        };

        return $query;
    }
}
