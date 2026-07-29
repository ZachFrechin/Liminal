<?php

declare(strict_types=1);

namespace Liminal\Console;

use Liminal\Exception\KernelException;
use Liminal\Kernel;
use Liminal\Registry\CommandRegistry;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

/**
 * Console entry point. Commands arrive exclusively through the CommandRegistry,
 * mirroring how routes arrive through the RouteRegistry.
 */
final readonly class Application
{
    public function __construct(private Kernel $kernel) {}

    /**
     * @throws KernelException when a registered command class does not extend Command
     */
    public function run(): int
    {
        $this->kernel->boot();

        $application = new ConsoleApplication($this->kernel->config()->string('app.name'), Kernel::VERSION);
        $container = $this->kernel->container();

        foreach ($this->kernel->registries()->get(CommandRegistry::class)->all() as $class) {
            $command = $container->get($class);

            if (!$command instanceof Command) {
                throw KernelException::notACommand($class);
            }

            $application->add($command);
        }

        return $application->run();
    }
}
