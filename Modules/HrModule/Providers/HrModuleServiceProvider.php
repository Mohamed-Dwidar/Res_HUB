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
        \Modules\EmployeeModule\app\Models\EmployeePosition::resolveRelationUsing('department',
            fn ($position) => $position->belongsTo(\Modules\HrModule\app\Models\Department::class, 'department_id')->withTrashed());
    }
}
