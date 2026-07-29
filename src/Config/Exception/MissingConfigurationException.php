<?php

declare(strict_types=1);

namespace Liminal\Config\Exception;

use RuntimeException;

final class MissingConfigurationException extends RuntimeException
{
    public static function for(string $key, string $type): self
    {
        return new self(sprintf('Configuration key "%s" is missing or is not a %s.', $key, $type));
    }
}
