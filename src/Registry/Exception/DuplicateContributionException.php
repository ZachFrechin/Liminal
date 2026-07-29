<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * Two contributions claimed the same key in one registry.
 *
 * Colliding silently — last contributor wins — would let one module clobber
 * another's route, permission or setting without anyone noticing, so duplicates
 * are refused while the registries are still filling. Deliberate overriding
 * becomes an explicit API when modules arrive in phase 2.
 */
final class DuplicateContributionException extends LogicException implements LiminalException
{
    public static function for(string $registry, string $key): self
    {
        return new self(sprintf(
            'Registry "%s" already holds a contribution for "%s": duplicates are refused during boot.',
            $registry,
            $key,
        ));
    }
}
