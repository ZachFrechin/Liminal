<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\UserProvider;
use SensitiveParameter;

/**
 * The default binding until the authentication module (phase 5) overrides it:
 * no users exist, so every protected route refuses and deny-by-default holds
 * end to end — instead of every request failing at container resolution.
 */
final readonly class NullUserProvider implements UserProvider
{
    public function byId(int $id): ?AuthenticatedUser
    {
        return null;
    }

    public function forLogin(string $identifier): ?LoginCandidate
    {
        return null;
    }

    public function rehash(int $id, #[SensitiveParameter] string $hash): void {}
}
