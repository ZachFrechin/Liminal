<?php

declare(strict_types=1);

namespace Liminal\Lib\System\Console;

use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Environment and coherence checks. Grows with each phase; today it covers the
 * PHP extensions the core needs and the state of the registries after boot.
 */
#[AsCommand(name: 'doctor', description: 'Check the environment and registry coherence')]
final class DoctorCommand extends Command
{
    private const REQUIRED_EXTENSIONS = ['json', 'pdo', 'mbstring'];

    public function __construct(private readonly RegistryCollection $registries)
    {
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
        $io->text(sprintf('  routes:              %d', count($this->registries->get(RouteRegistry::class)->all())));
        $io->text(sprintf('  entity namespaces:   %d', count($this->registries->get(EntityRegistry::class)->all())));
        $io->text(sprintf('  migration namespaces: %d', count($this->registries->get(MigrationRegistry::class)->all())));
        $io->text(sprintf('  frozen:              %s', $this->registries->isFrozen() ? 'yes' : 'no'));

        if (!$this->registries->isFrozen()) {
            $io->warning('Registries are not frozen: boot did not complete normally.');
            $failed = true;
        }

        if ($failed) {
            $io->error('Doctor found problems.');

            return Command::FAILURE;
        }

        $io->success('All checks passed.');

        return Command::SUCCESS;
    }
}
