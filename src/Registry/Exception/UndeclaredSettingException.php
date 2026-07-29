<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use LogicException;

final class UndeclaredSettingException extends LogicException
{
    public static function for(string $key): self
    {
        return new self(sprintf('Setting "%s" was never declared in the SettingsRegistry.', $key));
    }
}
