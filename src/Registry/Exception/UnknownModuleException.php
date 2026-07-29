<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A lookup asked for a module name that app.modules never declared — a
 * mistyped name or a manifest that was never wired in.
 */
final class UnknownModuleException extends LogicException implements LiminalException
{
    public static function for(string $name): self
    {
        return new self(sprintf('No module named "%s" is declared in app.modules.', $name));
    }
}
