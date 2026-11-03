<?php

namespace Modules\EmployeeModule\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // the employees' Access Log (docs/roles-plan.md)
        \Illuminate\Auth\Events\Login::class => [\Modules\EmployeeModule\app\Listeners\LogEmployeeAccess::class . '@handleLogin'],
        \Illuminate\Auth\Events\Failed::class => [\Modules\EmployeeModule\app\Listeners\LogEmployeeAccess::class . '@handleFailed'],
        \Illuminate\Auth\Events\Logout::class => [\Modules\EmployeeModule\app\Listeners\LogEmployeeAccess::class . '@handleLogout'],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
