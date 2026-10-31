<?php

namespace Modules\ContractModule\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ContractModuleServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'ContractModule';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'contractmodule';

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
}
