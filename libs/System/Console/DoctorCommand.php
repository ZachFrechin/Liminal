<?php

declare(strict_types=1);

namespace Liminal\Lib\System\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\ModuleRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TriggerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Environment and coherence checks. Grows with each phase; today it covers the
 * PHP extensions the core needs, the state of the registries after boot, and
 * the database (phase 1).
 *
 * The DatabaseHealth import is a sanctioned lib-to-lib edge (System →
 * Database, like Module → Database): app.libs loads Database first, and
 * System is the diagnostics surface. A kernel-level health-check registry can
 * replace it the day a third lib wants its own doctor line.
 */
#[AsCommand(name: 'doctor', description: 'Check the environment and registry coherence')]
final class DoctorCommand extends Command
{
    /** Kept aligned with composer.json's require section. */
    private const REQUIRED_EXTENSIONS = ['json', 'pdo', 'mbstring'];

    public function __construct(
        private readonly RegistryCollection $registries,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failed = false;

        $io->section('PHP');
        $io->text(sprintf('version: %s', PHP_VERSION));

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (extension_loaded($extension)) {
                $io->text(sprintf('  <info>OK</info> ext-%s', $extension));
            } else {
                $io->text(sprintf('  <error>MISSING</error> ext-%s', $extension));
                $failed = true;
            }
        }

        $io->section('Registries');
        $io->text(sprintf('  routes:               %d', count($this->registries->get(RouteRegistry::class)->all())));
        $io->text(sprintf('  entity namespaces:    %d', count($this->registries->get(EntityRegistry::class)->all())));
        $io->text(sprintf('  migration namespaces: %d', count($this->registries->get(MigrationRegistry::class)->all())));
        $io->text(sprintf('  modules declared:     %d', count($this->registries->get(ModuleRegistry::class)->all())));
        $io->text(sprintf('  hooks declared:       %d', count($this->registries->get(HookRegistry::class)->names())));
        $io->text(sprintf('  triggers declared:    %d', count($this->registries->get(TriggerRegistry::class)->names())));
        $io->text(sprintf('  frozen:               %s', $this->registries->isFrozen() ? 'yes' : 'no'));

        if (!$this->registries->isFrozen()) {
            $io->warning('Registries are not frozen: boot did not complete normally.');
            $failed = true;
        }

        $io->section('Database');
        $status = $this->database->check();

        switch ($status->kind) {
            case DatabaseStatusKind::NotConfigured:
                // A fresh checkout without a DSN is a valid state, not a failure.
                $io->text(sprintf('  <comment>SKIP</comment> %s', $status->detail));

                break;
            case DatabaseStatusKind::Unreachable:
                $io->text(sprintf('  <error>FAIL</error> %s', $status->detail));
                $failed = true;

                break;
            case DatabaseStatusKind::Ok:
                $io->text(sprintf('  <info>OK</info> server %s', $status->detail));

                break;
        }

        if ($failed) {
            $io->error('Doctor found problems.');

            return Command::FAILURE;
        }

        $io->success('All checks passed.');

        return Command::SUCCESS;
    }
}
