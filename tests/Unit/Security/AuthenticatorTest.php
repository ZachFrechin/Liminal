<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Security\Authentication\Authenticator;
use Liminal\Lib\Security\Authentication\LoginCandidate;
use Liminal\Lib\Security\Authentication\NullUserProvider;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionManager;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authenticator::class)]
final class AuthenticatorTest extends TestCase
{
    public function testAnUnknownIdentifierIsRefused(): void
    {
        $session = $this->session();

        self::assertNull($this->authenticator(new NullUserProvider())->attempt('ghost', 'whatever', $session));
        self::assertNull($session->userId());
    }

    public function testTheWrongPasswordIsRefused(): void
    {
        $session = $this->session();

        self::assertNull($this->authenticator($this->provider())->attempt('alice', 'wrong', $session));
        self::assertNull($session->userId());
    }

    /**
     * Offboarding is a data state: refused exactly like a wrong password,
     * never a server error.
     */
    public function testAUserWithoutCompaniesCannotLogIn(): void
    {
        $session = $this->session();
        $authenticator = $this->authenticator($this->provider(companies: []));

        self::assertNull($authenticator->attempt('alice', 'secret', $session));
        self::assertNull($session->userId());
    }

    public function testASuccessfulLoginRegeneratesTheSessionAndStoresTheUser(): void
    {
        $session = $this->session();
        $previousId = $session->id();

        $user = $this->authenticator($this->provider())->attempt('alice', 'secret', $session);

        self::assertNotNull($user);
        self::assertSame(7, $user->id());
        self::assertSame(7, $session->userId());
        // Anti-fixation: whatever id existed before the login is dead.
        self::assertNotSame($previousId, $session->id());
        self::assertTrue($session->isNew());
    }

    public function testLogoutClearsAndRegenerates(): void
    {
        $session = $this->session();
        $authenticator = $this->authenticator($this->provider());
        $authenticator->attempt('alice', 'secret', $session);

        $loggedInId = $session->id();
        $session->set('leftover', 'data');

        $authenticator->logout($session);

        self::assertNull($session->userId());
        self::assertNull($session->companyId());
        self::assertNull($session->get('leftover'));
        self::assertNotSame($loggedInId, $session->id());
    }

    /**
     * @param list<int> $companies
     */
    private function provider(array $companies = [1]): UserProvider
    {
        $user = new class ($companies) implements AuthenticatedUser {
            /** @param list<int> $companies */
            public function __construct(private readonly array $companies) {}

            public function id(): int
            {
                return 7;
            }

            public function accessibleCompanyIds(): array
            {
                return $this->companies;
            }
        };

        $hash = new PasswordHasher()->hash('secret');

        return new class ($user, $hash) implements UserProvider {
            public function __construct(
                private readonly AuthenticatedUser $user,
                private readonly string $hash,
            ) {}

            public function byId(int $id): ?AuthenticatedUser
            {
                return $id === $this->user->id() ? $this->user : null;
            }

            public function forLogin(string $identifier): ?LoginCandidate
            {
                return $identifier === 'alice' ? new LoginCandidate($this->user, $this->hash) : null;
            }
        };
    }

    private function authenticator(UserProvider $users): Authenticator
    {
        // The connection closure must never run: everything the authenticator
        // touches (regenerate included) is in-memory until persist.
        $manager = new SessionManager(
            static fn(): Connection => throw new LogicException('The authenticator must not touch the database.'),
            'liminal',
            7200,
            43200,
            false,
            0,
        );

        return new Authenticator($users, new PasswordHasher(), $manager);
    }

    private function session(): Session
    {
        return Session::fresh('a-fresh-session-id', new DateTimeImmutable());
    }
}
