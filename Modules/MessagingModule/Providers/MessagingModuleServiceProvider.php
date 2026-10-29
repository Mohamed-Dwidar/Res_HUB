<?php

namespace Modules\MessagingModule\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\MessagingModule\app\Models\MessageLog;
use Modules\ReservationModule\app\Models\Reservation;

class MessagingModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'MessagingModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'messagingmodule';

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
        // the WhatsApp icon state of the Schedule / list rows (MessagingService::button()): the reservation's sends, eager loaded by
        // the pages that show many rows (one query) — defined here so ReservationModule does not know the messages table
        Reservation::resolveRelationUsing('messageLogs', fn (Reservation $reservation) => $reservation
            ->morphMany(MessageLog::class, 'messageable')->select(['id', 'messageable_type', 'messageable_id', 'type', 'created_at'])->orderByDesc('id'));
    }
}
