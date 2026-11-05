<?php

namespace Modules\PayrollModule\Providers;

use Modules\HrModule\app\Models\Staff;
use Modules\HrModule\app\Services\StaffColumns;
use Modules\HrModule\app\Services\StaffTabs;
use Modules\PayrollModule\app\Models\EmployeeInsurance;
use Modules\PayrollModule\app\Models\EmployeeSalary;
use Modules\PayrollModule\app\Services\SalaryService;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PayrollModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'PayrollModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'payrollmodule';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // a staff file's salaries / insurance (HrModule doesn't know PayrollModule)
        Staff::resolveRelationUsing('salaries', fn ($staff) => $staff->hasMany(EmployeeSalary::class, 'employee_id'));
        Staff::resolveRelationUsing('insurance', fn ($staff) => $staff->hasOne(EmployeeInsurance::class, 'employee_id'));

        // the staff page's Salary tab (hr.salary; not for External staff)
        StaffTabs::add('salary', [
            'label' => 'Salary',
            'can' => 'hr.salary',
            'payroll' => true,
            'view' => 'payrollmodule::Salary.partials.tab',
            'data' => fn ($staff) => app(SalaryService::class)->tabData($staff),
        ]);

        // the Staff list's Basic Salary column (hr.salary): the basic salary in force today; External / none = "-"
        StaffColumns::add('salary_basic', [
            'title' => 'Basic Salary',
            'can' => 'hr.salary',
            'class' => 'text-center whitespace-nowrap',
            'with' => fn ($query) => $query->with(['salaries' => fn ($query) => $query->whereDate('effective_from', '<=', now()->toDateString())
                ->orderByDesc('effective_from')->orderByDesc('id')]),
            'value' => function ($staff) {
                $salary = $staff->is_external ? null : $staff->salaries->first();
                return $salary ? number_format($salary->basic, 2) : '<span class="text-slate-400">-</span>';
            },
        ]);
    }
}
