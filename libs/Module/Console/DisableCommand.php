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
 * Turns a module off for one company. The enablement row is kept (enabled=0)
 * as the audit trail; only company deletion cascades it away.
 */
#[AsCommand(name: 'module:disable', description: 'Disable a module for one company')]
final class DisableCommand extends Command
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
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Module name as declared in app.modules')
            ->addArgument('company-id', InputArgument::REQUIRED, 'Company the module is disabled for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot disable module: %s', $status->detail));

            return Command::FAILURE;
        }

        $name = ModuleArguments::name($input);

        if (!$this->modules->has($name)) {
            $io->error(sprintf('Unknown module "%s".', $name));
            ModuleArguments::listDeclared($io, $this->modules);

            return Command::INVALID;
        }

        $companyId = ModuleArguments::companyId($input);

        if ($companyId === null) {
            $io->error('company-id must be an integer.');

            return Command::INVALID;
        }

        try {
            $this->manager->disable($name, $companyId);
        } catch (ModuleException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Module "%s" disabled for company %d.', $name, $companyId));

        return Command::SUCCESS;
    }
}
