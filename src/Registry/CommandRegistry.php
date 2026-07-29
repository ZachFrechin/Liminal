<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Symfony\Component\Console\Command\Command;

final class CommandRegistry extends AbstractRegistry
{
    /** @var list<class-string<Command>> */
    private array $commands = [];

    /**
     * @param class-string<Command> $command service id resolved through the container
     */
    public function add(string $command): void
    {
        $this->assertMutable();

        $this->commands[] = $command;
    }

    /** @return list<class-string<Command>> */
    public function all(): array
    {
        return $this->commands;
    }
}
