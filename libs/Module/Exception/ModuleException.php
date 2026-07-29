<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;

/**
 * A module lifecycle operation was asked for something the declared set or
 * the database state cannot honour. Messages carry their remedy: they are
 * printed verbatim by the module:* commands.
 */
final class ModuleException extends RuntimeException implements LiminalException
{
    /**
     * @param list<string> $declared
     */
    public static function unknown(string $name, array $declared): self
    {
        return new self(sprintf(
            'Module "%s" is not declared in app.modules. Declared modules: %s.',
            $name,
            $declared === [] ? 'none' : '"' . implode('", "', $declared) . '"',
        ));
    }

    public static function notInstalled(string $name): self
    {
        return new self(sprintf('Module "%s" is not installed. Run "module:install %s" first.', $name, $name));
    }

    public static function unknownCompany(int $companyId): self
    {
        return new self(sprintf('No company with id %d exists: modules are enabled per existing company.', $companyId));
    }

    public static function coreTablesMissing(): self
    {
        return new self('The core tables are missing. Run "install" before managing modules.');
    }
}
