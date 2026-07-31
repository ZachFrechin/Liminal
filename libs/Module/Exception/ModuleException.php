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

    public static function invalidSlug(string $slug): self
    {
        return new self(sprintf(
            'The module name "%s" is not a slug: lowercase [a-z][a-z0-9_]*, 64 characters at most — the same grammar the boot enforces.',
            $slug,
        ));
    }

    public static function directoryExists(string $path): self
    {
        return new self(sprintf('The directory "%s" already exists — the scaffolder never overwrites.', $path));
    }

    public static function stubMissing(string $path): self
    {
        return new self(sprintf('The stub "%s" is missing or unreadable: the module lib installation is broken.', $path));
    }

    public static function directoryNotWritable(string $path): self
    {
        return new self(sprintf('Cannot create the directory "%s".', $path));
    }
}
