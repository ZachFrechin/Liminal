<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Registry\MigrationRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs pending migrations — all namespaces by default, one module's with
 * --module, riding the ScopedPlanCalculator so enabling one module never
 * drags another's migrations along.
 */
#[AsCommand(name: 'migrate', description: 'Run pending migrations, optionally scoped to one namespace')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $migrations,
        private readonly MigrationRegistry $registry,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('module', null, InputOption::VALUE_REQUIRED, 'Restrict to one migration namespace');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot migrate: %s', $status->detail));

            return Command::FAILURE;
        }

        $module = $input->getOption('module');
        $namespace = is_string($module) ? trim($module, '\\') : null;

        if ($namespace !== null && !array_key_exists($namespace, $this->registry->all())) {
            $io->error(sprintf('Unknown migration namespace "%s".', $namespace));
            $io->listing(array_keys($this->registry->all()));

            return Command::INVALID;
        }

        $executed = $this->migrations->migrateToLatest($namespace);

        if ($executed === []) {
            $io->success('Nothing to migrate: everything is up to date.');

            return Command::SUCCESS;
        }

        $io->listing($executed);
        $io->success(sprintf('Executed %d migration(s).', count($executed)));

        return Command::SUCCESS;
    }
}
