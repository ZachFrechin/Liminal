<?php

declare(strict_types=1);

namespace Liminal\Lib\System\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Database\Install\FirstCompanySeeder;
use Liminal\Lib\Database\Install\SeedResult;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Registry\ModuleRegistry;
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
        private readonly ModuleRegistry $modules,
        private readonly ModuleManager $manager,
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

        $io->section('Modules');
        $enabled = $this->enableDeclaredModules($io, $result);

        if ($executed === [] && !$result->wasSeeded() && $enabled === []) {
            $io->success('Already installed; nothing to do.');
        } else {
            $io->success('Installation complete.');
        }

        return Command::SUCCESS;
    }

    /**
     * A brand new instance has no way to log in and enable its own modules —
     * the login page is public, but everything behind it would 404. So the
     * virgin install enables what the installation declares.
     *
     * Only the virgin path: re-running install must never re-enable a module an
     * operator deliberately disabled. System knows ModuleRegistry and
     * ModuleManager, both generic — it never learns a module's name.
     *
     * @return list<string> the modules enabled by this run
     */
    private function enableDeclaredModules(SymfonyStyle $io, SeedResult $result): array
    {
        $declared = array_keys($this->modules->all());

        if ($declared === []) {
            $io->text('  none declared');

            return [];
        }

        if (!$result->wasSeeded() || $result->companyId === null) {
            $io->text('  instance already installed; module state left untouched');

            return [];
        }

        foreach ($declared as $name) {
            $this->manager->install($name);
            $this->manager->enable($name, $result->companyId);
            $io->text(sprintf('  enabled "%s" for company %d', $name, $result->companyId));
        }

        return $declared;
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
