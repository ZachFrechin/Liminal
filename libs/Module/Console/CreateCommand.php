<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Console;

use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Lib\Module\Scaffold\ModuleScaffolder;
use Liminal\Registry\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The module builder: renders a complete CRUD module from the stubs. Pure
 * filesystem — no Connection anywhere near this constructor — and it never
 * edits config/app.php: declaring the module is the operator's gesture,
 * printed at the end, because a generator that edits configuration would be
 * a module touching the core.
 *
 * The icon travels verbatim: validating it here would import the rendering
 * lib into this one, against the declared lib order (rendering already
 * consumes the module lib for its menu). An unknown glyph throws at first
 * render — pick one from IconSet.
 */
#[AsCommand(
    name: 'module:create',
    description: 'Generate a complete CRUD module skeleton',
)]
final class CreateCommand extends Command
{
    public function __construct(
        private readonly ModuleScaffolder $scaffolder,
        private readonly ModuleRegistry $modules,
        private readonly string $modulesDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The module slug (lowercase, e.g. "bookshelf")');
        $this->addOption('icon', null, InputOption::VALUE_REQUIRED, 'IconSet glyph for the menu entry', 'building-2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $nameArgument = $input->getArgument('name');
        $slug = is_string($nameArgument) ? trim($nameArgument) : '';
        $iconOption = $input->getOption('icon');
        $icon = is_string($iconOption) && trim($iconOption) !== '' ? trim($iconOption) : 'building-2';

        if ($this->modules->has($slug)) {
            $io->error(sprintf('A module named "%s" is already declared in app.modules.', $slug));

            return Command::INVALID;
        }

        try {
            $written = $this->scaffolder->scaffold($this->modulesDir, $slug, $icon, date('YmdHis'));
        } catch (ModuleException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $studly = str_replace('_', '', ucwords($slug, '_'));

        $io->success(sprintf('Module "%s" generated: %d files under modules/%s.', $slug, count($written), $studly));
        $io->listing($written);

        $io->section('Next steps');
        $io->writeln(sprintf('  1. Declare it in config/app.php, at the end of the modules list:'));
        $io->writeln(sprintf('         Liminal\Module\%s\%sModule::class,', $studly, $studly));
        $io->writeln('  2. php bin/liminal migrate            # or module:install ' . $slug);
        $io->writeln('  3. php bin/liminal module:enable ' . $slug . ' <company-id>');
        $io->newLine();
        $io->writeln('  composer check stays green on the generated code — that is the contract.');

        return Command::SUCCESS;
    }
}
