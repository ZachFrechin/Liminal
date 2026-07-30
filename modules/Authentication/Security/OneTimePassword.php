<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

/**
 * The initial password story while no mailer exists: the server invents a
 * strong secret, shows it exactly once, and only its hash survives.
 *
 * 16 random bytes as base64url — 22 characters, ~128 bits, far above the
 * 8-character floor the interactive command enforces on human choices.
 */
final readonly class OneTimePassword
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
