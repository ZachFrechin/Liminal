<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthenticatedUser;
use SensitiveParameter;

/**
 * A user plus their stored password hash, alive only for the duration of one
 * verification. Never log, serialize or store this object: the hash rides on
 * it precisely so it does not have to live on the user object.
 */
final readonly class LoginCandidate
{
    public function __construct(
        public AuthenticatedUser $user,
        #[SensitiveParameter]
        public string $passwordHash,
    ) {}
}
