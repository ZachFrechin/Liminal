<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Registry\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs — or upgrades — one declared module: its own migrations, then the
 * core_module record.
 */
#[AsCommand(name: 'module:install', description: 'Install or upgrade a declared module')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly ModuleManager $manager,
        private readonly ModuleRegistry $modules,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Module name as declared in app.modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot install module: %s', $status->detail));

            return Command::FAILURE;
        }

        $name = ModuleArguments::name($input);

        if (!$this->modules->has($name)) {
            $io->error(sprintf('Unknown module "%s".', $name));
            ModuleArguments::listDeclared($io, $this->modules);

            return Command::INVALID;
        }

        try {
            $outcome = $this->manager->install($name);
        } catch (ModuleException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->section('Migrations');

        if ($outcome->executedMigrations === []) {
            $io->text('  nothing to migrate');
        } else {
            $io->listing($outcome->executedMigrations);
        }

        if ($outcome->wasFreshInstall()) {
            $io->success(sprintf('Module "%s" installed (version %s).', $name, $outcome->version));
        } elseif ($outcome->changedVersion()) {
            $io->success(sprintf(
                'Module "%s" upgraded from %s to %s.',
                $name,
                $outcome->previousVersion,
                $outcome->version,
            ));
        } else {
            $io->success(sprintf('Module "%s" already installed (version %s).', $name, $outcome->version));
        }

        return Command::SUCCESS;
    }
}
