<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * Wiring failures of the dispatch side. Dispatching an undeclared name is a
 * typo in code, not a data condition — the Gate's undeclared-permission
 * precedent: fail loud, never "no listeners ran" silence.
 */
final class HookException extends LogicException implements LiminalException
{
    public static function undeclaredHook(string $hook): self
    {
        return new self(sprintf('Hook "%s" was never declared by any contributor.', $hook));
    }

    public static function undeclaredTrigger(string $name): self
    {
        return new self(sprintf('Trigger "%s" was never declared by any contributor.', $name));
    }

    public static function notAHookListener(string $service): self
    {
        return new self(sprintf('Service "%s" does not implement HookListener.', $service));
    }

    public static function notATriggerListener(string $service): self
    {
        return new self(sprintf('Service "%s" does not implement TriggerListener.', $service));
    }

    public static function notAService(string $id): self
    {
        return new self(sprintf('Listener id "%s" did not resolve to an object.', $id));
    }
}
