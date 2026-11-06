<?php

namespace Modules\ReservationModule\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ReservationModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'ReservationModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'reservationmodule';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        // to_run STEP 5b: the imported periods → renewal_of
        \Modules\ReservationModule\app\Console\LinkRenewalsCommand::class,
        // 2026-11-06: reservations of members that no longer exist (old import)
        \Modules\ReservationModule\app\Console\DeleteOrphanReservationsCommand::class,
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
