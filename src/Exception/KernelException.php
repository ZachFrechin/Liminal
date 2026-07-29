<?php

declare(strict_types=1);

namespace Liminal\Exception;

use Liminal\Registry\Contract\Contributor;
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

    public static function logDirectory(string $directory): self
    {
        return new self(sprintf('Unable to create log directory "%s".', $directory));
    }
}
