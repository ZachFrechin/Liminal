<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthenticatedUser;

/**
 * The outcome of one login attempt — three states a nullable user could not
 * express: granted, refused, or refused-without-even-trying because the
 * throttle is holding this identifier or address.
 */
final readonly class LoginResult
{
    private function __construct(
        public ?AuthenticatedUser $user,
        public ?int $retryAfterSeconds,
    ) {}

    public static function granted(AuthenticatedUser $user): self
    {
        return new self($user, null);
    }

    public static function refused(): self
    {
        return new self(null, null);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(null, $retryAfterSeconds);
    }

    public function isGranted(): bool
    {
        return $this->user !== null;
    }

    public function wasThrottled(): bool
    {
        return $this->retryAfterSeconds !== null;
    }
}
