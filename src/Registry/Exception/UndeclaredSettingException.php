<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A setting was read without ever being declared. Reads are only legal against
 * declared definitions — the first registry-enforced module boundary.
 */
final class UndeclaredSettingException extends LogicException implements LiminalException
{
    public static function for(string $key): self
    {
        return new self(sprintf('Setting "%s" was never declared in the SettingsRegistry.', $key));
    }
}
