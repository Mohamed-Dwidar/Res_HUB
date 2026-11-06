<?php

namespace Modules\PaymentModule\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class PaymentModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'PaymentModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'paymentmodule';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        \Modules\PaymentModule\app\Console\RefreshPaymentsCommand::class,
        \Modules\PaymentModule\app\Console\RecalculateShiftsCommand::class,
        \Modules\PaymentModule\app\Console\MoveVatPaymentsCommand::class,
    ];

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
        // the employees' shift reminders on every page / ajax action (it does nothing for other users)
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('web', \Modules\PaymentModule\app\Http\Middleware\ShiftReminder::class);
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
