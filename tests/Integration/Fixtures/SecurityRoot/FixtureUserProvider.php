<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Security\Authentication\LoginCandidate;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Password\PasswordHasher;

/**
 * The in-memory provider the fixture contributor binds OVER the lib's
 * NullUserProvider — deliberately exercising the exact definition-layering
 * path the phase-5 authentication module will use.
 */
final class FixtureUserProvider implements UserProvider
{
    /** @var array<string, array{user: FixtureUser, hash: string}> */
    private array $users;

    public function __construct()
    {
        $hasher = new PasswordHasher();

        $this->users = [
            'alice' => ['user' => new FixtureUser(7, [1, 2]), 'hash' => $hasher->hash('alice-secret')],
            'bob' => ['user' => new FixtureUser(8, [2]), 'hash' => $hasher->hash('bob-secret')],
        ];
    }

    public function byId(int $id): ?AuthenticatedUser
    {
        foreach ($this->users as $entry) {
            if ($entry['user']->id() === $id) {
                return $entry['user'];
            }
        }

        return null;
    }

    public function forLogin(string $identifier): ?LoginCandidate
    {
        $entry = $this->users[$identifier] ?? null;

        return $entry === null ? null : new LoginCandidate($entry['user'], $entry['hash']);
    }
}
