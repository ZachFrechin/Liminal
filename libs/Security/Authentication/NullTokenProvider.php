<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\TokenProvider;
use SensitiveParameter;

/**
 * The default binding until the authentication module overrides it: no token
 * exists, so every presented bearer credential refuses — an installation
 * without the authentication module stays fail-closed instead of failing at
 * container resolution.
 */
final readonly class NullTokenProvider implements TokenProvider
{
    public function authenticate(#[SensitiveParameter] string $rawToken): ?int
    {
        return null;
    }
}
