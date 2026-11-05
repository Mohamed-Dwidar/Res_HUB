<?php

namespace Modules\HrModule\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class HrModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'HrModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'hrmodule';

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

        // a job title's department (2026-11-05: an employee's department = his job title's); EmployeeModule doesn't know HrModule
        // the staff page's Leaves tab (H3, hr.manage; not for External staff); ?year= picks the year (default this year)
        \Modules\HrModule\app\Services\StaffTabs::add('leaves', [
            'label' => 'Leaves',
            'can' => 'hr.manage',
            'payroll' => true,
            'view' => 'hrmodule::Leave.partials.tab',
            'data' => function ($staff) {
                $service = app(\Modules\HrModule\app\Services\LeaveService::class);
                $year = (int) request('year') ?: today()->year;
                $year = $year >= 2000 && $year <= 2100 ? $year : today()->year;
                $leaves = $service->list($staff, $year);
                return ['year' => $year, 'leaves' => $leaves, 'balances' => $service->balances($staff, $year, $leaves)];
            },
        ]);

        \Modules\EmployeeModule\app\Models\EmployeePosition::resolveRelationUsing('department',
            fn ($position) => $position->belongsTo(\Modules\HrModule\app\Models\Department::class, 'department_id')->withTrashed());
    }
}
