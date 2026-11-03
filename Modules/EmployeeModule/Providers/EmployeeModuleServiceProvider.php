<?php

namespace Modules\EmployeeModule\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\EmployeeModule\app\Services\RoleService;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class EmployeeModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'EmployeeModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'employeemodule';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

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
        // the employees' idle timeout (their role's hours) on every request; it does nothing for other users
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('web', \Modules\EmployeeModule\app\Http\Middleware\EmployeeIdleTimeout::class);
        $this->registerGate();
    }

    /**
     * Roles & permissions (docs/roles-plan.md, R0): the owner = every ability, an employee = his role's keys (RoleService::allows()).
     * Combined abilities for the routes several features share (any one of the keys is enough):
     * - reservations.form: the reservation form's live checks (availability, manual VAT) = add / edit / renew;
     * - members.pick: the Member field's search = the member pages or a form that picks a member;
     * - payments.form: the Cash In form's reservation / package list = add or edit own.
     */
    private function registerGate(): void
    {
        $this->app->singleton(RoleService::class);
        Gate::before(fn ($user, $ability) => $this->app->make(RoleService::class)->allows($user, $ability));

        $any = fn (array $abilities) => fn ($user) => Gate::forUser($user)->any($abilities);
        Gate::define('reservations.form', $any(['reservations.create', 'reservations.edit', 'reservations.renew']));
        Gate::define('members.pick', $any(['members.view', 'reservations.create', 'reservations.edit', 'reservations.renew',
            'packages.create', 'packages.edit', 'payments.create', 'payments.edit_own_open']));
        Gate::define('payments.form', $any(['payments.create', 'payments.edit_own_open']));
    }

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
