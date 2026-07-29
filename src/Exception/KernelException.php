<?php

declare(strict_types=1);

namespace Liminal\Exception;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\Module;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Boot-time assembly failures raised by the kernel itself: a lib that cannot
 * be instantiated, a hijacked service id, an unusable directory. One class,
 * one named constructor per way boot can go wrong.
 */
final class KernelException extends RuntimeException implements LiminalException
{
    public static function unavailable(string $what): self
    {
        return new self(sprintf('Kernel %s is unavailable: boot() did not complete.', $what));
    }

    public static function libMissing(string $class): self
    {
        return new self(sprintf('Lib "%s" does not exist.', $class));
    }

    public static function libNeedsArguments(string $class): self
    {
        return new self(sprintf(
            'Lib "%s" must be constructible without arguments: contributors are instantiated before the container exists. Move dependencies into definitions() closures or contribute().',
            $class,
        ));
    }

    public static function libNotAContributor(string $class): self
    {
        return new self(sprintf('Lib "%s" must implement %s.', $class, Contributor::class));
    }

    public static function reservedService(string $lib, string $id): self
    {
        return new self(sprintf('Lib "%s" may not redefine kernel service "%s".', $lib, $id));
    }

    public static function unexpectedServiceType(string $class): self
    {
        return new self(sprintf('Container returned an unexpected type for "%s".', $class));
    }

    public static function notACommand(string $class): self
    {
        return new self(sprintf('Command "%s" must extend %s.', $class, Command::class));
    }

    public static function notAMiddleware(string $class): self
    {
        return new self(sprintf('Middleware "%s" must implement %s.', $class, MiddlewareInterface::class));
    }

    public static function moduleMissing(string $class): self
    {
        return new self(sprintf('Module "%s" does not exist.', $class));
    }

    public static function moduleNeedsArguments(string $class): self
    {
        return new self(sprintf(
            'Module "%s" must be constructible without arguments: modules are instantiated before the container exists. Move dependencies into definitions() closures or contribute().',
            $class,
        ));
    }

    public static function notAModule(string $class): self
    {
        return new self(sprintf('Module "%s" must implement %s.', $class, Module::class));
    }

    public static function moduleName(string $class, string $name): self
    {
        return new self(sprintf(
            'Module "%s" declares the invalid name "%s": lowercase slug (a-z, 0-9, _), 64 characters at most.',
            $class,
            $name,
        ));
    }

    public static function moduleVersion(string $class, string $version): self
    {
        return new self(sprintf(
            'Module "%s" declares the invalid version "%s": a non-empty string of 32 characters at most.',
            $class,
            $version,
        ));
    }

    public static function moduleRouteName(string $module, string $path): self
    {
        return new self(sprintf(
            'Module "%s" contributed the route "%s" without a "%s." name prefix: the module gate reads that prefix, so an unprefixed route would never be gated.',
            $module,
            $path,
            $module,
        ));
    }

    public static function moduleMigrationsUnregistered(string $module, string $namespace): self
    {
        return new self(sprintf(
            'Module "%s" declares migration namespace "%s" but contribute() never registered it in the MigrationRegistry.',
            $module,
            $namespace,
        ));
    }

    public static function middlewareOutsideErrorHandler(string $class): self
    {
        return new self(sprintf(
            'Middleware "%s" is ordered outside the error handler: its exceptions would escape unrendered. Use a priority above MiddlewareRegistry::ERROR_HANDLER.',
            $class,
        ));
    }

    public static function middlewareBehindDispatcher(string $class): self
    {
        return new self(sprintf(
            'Middleware "%s" is ordered behind the dispatcher and would never run. Use a priority below MiddlewareRegistry::DISPATCH.',
            $class,
        ));
    }

    public static function logDirectory(string $directory): self
    {
        return new self(sprintf('Unable to create log directory "%s".', $directory));
    }
}
