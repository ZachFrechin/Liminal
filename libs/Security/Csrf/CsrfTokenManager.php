<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Csrf;

use Liminal\Lib\Security\Session\Session;
use Random\RandomException;

/**
 * One synchronizer token per session, generated lazily — that first
 * generation is what organically creates the anonymous session's first write,
 * on the first form render. The Authenticator removes the key on login, so a
 * fresh token greets every privilege change.
 */
final readonly class CsrfTokenManager
{
    public const string KEY = '_csrf';

    /**
     * @throws RandomException when the platform cannot produce secure randomness
     */
    public function token(Session $session): string
    {
        $existing = $session->get(self::KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session->set(self::KEY, $token);

        return $token;
    }

    public function validate(Session $session, string $presented): bool
    {
        $stored = $session->get(self::KEY);

        if (!is_string($stored) || $stored === '' || $presented === '') {
            return false;
        }

        // Stored first: hash_equals' known-string argument order.
        return hash_equals($stored, $presented);
    }
}
