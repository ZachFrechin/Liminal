<?php

declare(strict_types=1);

namespace Liminal\Lib\System\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Database\Install\FirstCompanySeeder;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns an empty database into a runnable instance: every registered
 * migration namespace, then the first company. Idempotent by construction —
 * re-running migrates nothing and never seeds twice — and the one command
 * allowed to write where the doctor only looks.
 */
#[AsCommand(name: 'install', description: 'Migrate every registered namespace and seed the first company')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly DatabaseHealth $database,
        private readonly MigrationRunner $migrations,
        private readonly FirstCompanySeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-code', null, InputOption::VALUE_REQUIRED, 'Code of the first company', 'MAIN')
            ->addOption('company-name', null, InputOption::VALUE_REQUIRED, 'Display name of the first company', 'Main company');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            // Unlike the doctor, install cannot treat NotConfigured as valid:
            // a database is its entire job.
            $io->error(sprintf('Cannot install: %s', $status->detail));

            return Command::FAILURE;
        }

        $io->section('Migrations');
        $executed = $this->migrations->migrateToLatest();

        if ($executed === []) {
            $io->text('  nothing to migrate');
        } else {
            $io->listing($executed);
        }

        $io->section('Company');
        $code = $this->stringOption($input, 'company-code');
        $result = $this->seeder->seed($code, $this->stringOption($input, 'company-name'));

        $io->text($result->wasSeeded()
            ? sprintf('  seeded "%s" (id %d)', $code, $result->companyId)
            : sprintf('  core_company already has %d row(s); seed skipped', $result->existing));

        if ($executed === [] && !$result->wasSeeded()) {
            $io->success('Already installed; nothing to do.');
        } else {
            $io->success('Installation complete.');
        }

        return Command::SUCCESS;
    }

    /**
     * Both options carry string defaults, so this always narrows; the empty
     * string is the unreachable fallback the type system asks for.
     */
    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }
}
