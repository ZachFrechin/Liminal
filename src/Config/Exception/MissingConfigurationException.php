<?php

declare(strict_types=1);

namespace Liminal\Config\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;

/**
 * A configuration file or key the core relies on is absent or has the wrong
 * shape. Raised eagerly — at load or first typed read — so a deployment
 * mistake surfaces as one clear boot failure instead of a distant null.
 */
final class MissingConfigurationException extends RuntimeException implements LiminalException
{
    public static function for(string $key, string $type): self
    {
        return new self(sprintf('Configuration key "%s" is missing or is not a %s.', $key, $type));
    }

    public static function file(string $path): self
    {
        return new self(sprintf('Configuration file "%s" is missing or does not return an array.', $path));
    }
}
