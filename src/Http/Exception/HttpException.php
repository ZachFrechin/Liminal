<?php

declare(strict_types=1);

namespace Liminal\Http\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;

/**
 * A request-level error carrying its HTTP identity: the ErrorHandlerMiddleware
 * renders statusCode(), message and headers() verbatim, so messages here must
 * be safe to show a client. Named constructors cover the routing outcomes.
 */
final class HttpException extends RuntimeException implements LiminalException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        string $message,
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function notFound(string $path): self
    {
        return new self(404, sprintf('No route matches "%s".', $path));
    }

    /**
     * Deliberate RFC 7235 deviation: no WWW-Authenticate header — no
     * registered scheme describes cookie sessions, and the rendering phase
     * replaces this response with a redirect to the login page anyway.
     */
    public static function unauthorized(): self
    {
        return new self(401, 'Authentication required.');
    }

    /**
     * 403, not the non-standard 419: the error handler renders the code
     * verbatim and standard codes are the norm. The message is distinct from a
     * permission 403 so the two are told apart client-side.
     */
    public static function csrfTokenMismatch(): self
    {
        return new self(403, 'Invalid or missing CSRF token.');
    }

    /**
     * The permission 403. The message deliberately does not name the code that
     * was refused: telling a client which permission it lacks maps the
     * authorization model for whoever is probing.
     */
    public static function forbidden(): self
    {
        return new self(403, 'You are not allowed to do that.');
    }

    /**
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Method not allowed.', ['Allow' => implode(', ', $allowed)]);
    }
}
