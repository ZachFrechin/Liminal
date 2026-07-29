<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Lib\Module\ModuleManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only view of every module the instance knows: declared ones in
 * declaration order, then installed rows whose module left app.modules.
 * Like the doctor, a missing DSN is a reported SKIP.
 */
#[AsCommand(name: 'module:list', description: 'Show declared and installed modules with their per-company state')]
final class ListCommand extends Command
{
    public function __construct(
        private readonly ModuleManager $manager,
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
            $io->error(sprintf('Cannot list modules: %s', $status->detail));

            return Command::FAILURE;
        }

        try {
            $overview = $this->manager->overview();
        } catch (ModuleException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rows = [];

        foreach ($overview as $module) {
            $rows[] = [
                $module->name,
                $module->declaredVersion ?? '(not declared)',
                $module->installedVersion ?? '—',
                $module->enabledCompanyIds === [] ? '—' : implode(', ', $module->enabledCompanyIds),
            ];
        }

        $io->table(['Module', 'Declared', 'Installed', 'Enabled companies'], $rows);

        return Command::SUCCESS;
    }
}
