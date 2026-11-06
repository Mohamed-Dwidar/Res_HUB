<?php

namespace Modules\ReportModule\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

// ReportModule (docs/reports-plan.md): read only reports, one permission key each (reports.*)
class ReportModuleServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'ReportModule';

    protected string $nameLower = 'reportmodule';

    protected array $commands = [];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
