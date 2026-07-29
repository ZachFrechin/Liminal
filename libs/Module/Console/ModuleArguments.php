<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Console;

use Liminal\Registry\ModuleRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared argument parsing for the module:* commands — the console API types
 * everything mixed, and the four commands must narrow it identically.
 */
final class ModuleArguments
{
    private function __construct() {}

    public static function name(InputInterface $input): string
    {
        $name = $input->getArgument('name');

        return is_string($name) ? $name : '';
    }

    /**
     * @return int|null null when the argument is not an integer
     */
    public static function companyId(InputInterface $input): ?int
    {
        $raw = $input->getArgument('company-id');

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_INT) === false ? null : (int) $raw;
    }

    public static function listDeclared(SymfonyStyle $io, ModuleRegistry $modules): void
    {
        $declared = array_keys($modules->all());

        if ($declared === []) {
            $io->text('  no module is declared in app.modules');

            return;
        }

        $io->listing($declared);
    }
}
