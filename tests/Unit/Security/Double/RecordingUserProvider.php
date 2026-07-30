<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security\Double;

use Liminal\Lib\Security\Authentication\LoginCandidate;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\UserProvider;
use PHPUnit\Framework\Assert;
use SensitiveParameter;

/**
 * One in-memory user, plus the two things tests need to observe: whether a
 * rehash was written, and whether a lookup happened at all.
 */
final class RecordingUserProvider implements UserProvider
{
    public ?string $rehashed = null;

    public bool $refuseLookups = false;

    public function __construct(
        private readonly AuthenticatedUser $user,
        private readonly string $hash,
        private readonly string $identifier = 'alice',
    ) {}

    public function byId(int $id): ?AuthenticatedUser
    {
        return $id === $this->user->id() ? $this->user : null;
    }

    public function forLogin(string $identifier): ?LoginCandidate
    {
        if ($this->refuseLookups) {
            Assert::fail('A throttled attempt must not reach the user lookup.');
        }

        return $identifier === $this->identifier ? new LoginCandidate($this->user, $this->hash) : null;
    }

    public function rehash(int $id, #[SensitiveParameter] string $hash): void
    {
        $this->rehashed = $hash;
    }
}
