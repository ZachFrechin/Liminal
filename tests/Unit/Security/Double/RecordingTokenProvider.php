<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security\Double;

use Liminal\Lib\Security\Contract\TokenProvider;
use SensitiveParameter;

/**
 * One in-memory token, plus what tests need to observe: whether a lookup
 * happened at all — a request without a bearer header must never cost one.
 */
final class RecordingTokenProvider implements TokenProvider
{
    public bool $consulted = false;

    public function __construct(
        private readonly string $accepts,
        private readonly int $userId,
    ) {}

    public function authenticate(#[SensitiveParameter] string $rawToken): ?int
    {
        $this->consulted = true;

        return $rawToken === $this->accepts ? $this->userId : null;
    }
}
