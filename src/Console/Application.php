<?php

declare(strict_types=1);

namespace Liminal\Console;

use Liminal\Kernel;
use Liminal\Registry\CommandRegistry;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

/**
 * Console entry point. Commands arrive exclusively through the CommandRegistry,
 * mirroring how routes arrive through the RouteRegistry.
 */
final class Application
{
    public function __construct(private readonly Kernel $kernel) {}

    public function run(): int
    {
        $this->kernel->boot();

        $application = new ConsoleApplication('Liminal', '0.1.0-dev');
        $container = $this->kernel->container();

        foreach ($this->kernel->registries()->get(CommandRegistry::class)->all() as $class) {
            $command = $container->get($class);

            if (!$command instanceof Command) {
                throw new RuntimeException(sprintf('Command "%s" must extend %s.', $class, Command::class));
            }

            $application->add($command);
        }

        return $application->run();
    }
}
