<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only view of pending migrations per namespace. Like the doctor, a
 * missing DSN is a reported SKIP, and nothing here mutates the database —
 * not even the metadata table.
 */
#[AsCommand(name: 'migrate:status', description: 'Show pending migrations per namespace')]
final class MigrateStatusCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $migrations,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind === DatabaseStatusKind::NotConfigured) {
            $io->text(sprintf('  <comment>SKIP</comment> %s', $status->detail));

            return Command::SUCCESS;
        }

        if ($status->kind === DatabaseStatusKind::Unreachable) {
            $io->error(sprintf('Cannot read migration status: %s', $status->detail));

            return Command::FAILURE;
        }

        $rows = [];

        foreach ($this->migrations->pendingByNamespace() as $namespace => $pending) {
            $rows[] = [$namespace, $pending];
        }

        $io->table(['Namespace', 'Pending'], $rows);

        return Command::SUCCESS;
    }
}
