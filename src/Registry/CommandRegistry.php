<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;
use Symfony\Component\Console\Command\Command;

/**
 * Collects the console command classes contributed during boot; the console
 * application resolves each through the container, mirroring how routes reach
 * their handlers.
 */
final class CommandRegistry extends AbstractRegistry
{
    /** @var list<class-string<Command>> */
    private array $commands = [];

    /**
     * @param class-string<Command> $command service id resolved through the container
     *
     * @throws DuplicateContributionException when the command class is already registered
     */
    public function add(string $command): void
    {
        $this->assertMutable();

        if (in_array($command, $this->commands, true)) {
            throw DuplicateContributionException::for(static::class, $command);
        }

        $this->commands[] = $command;
    }

    /** @return list<class-string<Command>> */
    public function all(): array
    {
        return $this->commands;
    }
}
