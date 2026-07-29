<?php

declare(strict_types=1);

namespace Liminal\Http\Exception;

use RuntimeException;

final class HttpException extends RuntimeException
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
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Method not allowed.', ['Allow' => implode(', ', $allowed)]);
    }
}
