<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

/**
 * Validates the ?redirect= target the HTML error middleware generates but
 * deliberately does not consume — validation belongs to whoever acts on it.
 *
 * Every refusal falls back to the default silently. An error page here would be
 * a UX cliff and a free oracle, and echoing the rejected value back into the
 * response is how reflected-XSS bugs are born.
 */
final readonly class LoginRedirect
{
    /** Long enough for any real deep link, short enough to bound the header. */
    private const int MAX_LENGTH = 2048;

    public function resolve(?string $target): ?string
    {
        if ($target === null || $target === '' || strlen($target) > self::MAX_LENGTH) {
            return null;
        }

        // Must be a path on this host: an absolute URL or a protocol-relative
        // "//evil.com" would make the login page an open redirect.
        if ($target[0] !== '/' || str_starts_with($target, '//')) {
            return null;
        }

        // Browsers fold backslashes to forward slashes, so "/\evil.com" is
        // "//evil.com" by the time it reaches the address bar.
        if (str_contains($target, '\\')) {
            return null;
        }

        // A CR or LF in a Location header is response splitting.
        if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return null;
        }

        return $target;
    }
}
