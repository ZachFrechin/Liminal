<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Password;

use SensitiveParameter;

/**
 * The one place passwords are hashed and verified.
 *
 * PASSWORD_DEFAULT — bcrypt, cost 12 on PHP 8.4 (OWASP floor is 10) — and
 * deliberately not a runtime preference for argon2id: its availability is
 * compile-dependent, and a mixed fleet would flap needsRehash() on every
 * login. verify() is algorithm-agnostic, so a future deliberate switch is one
 * constant plus natural rehash-on-login. Known bcrypt property: input beyond
 * 72 bytes is truncated.
 */
final readonly class PasswordHasher
{
    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
