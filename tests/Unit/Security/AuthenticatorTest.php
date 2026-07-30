<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Security\Authentication\Authenticator;
use Liminal\Lib\Security\Authentication\AuthEvent;
use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Authentication\NullAuthEventLog;
use Liminal\Lib\Security\Authentication\NullLoginThrottle;
use Liminal\Lib\Security\Authentication\NullUserProvider;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\AuthEventLog;
use Liminal\Lib\Security\Contract\LoginThrottle;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionManager;
use Liminal\Tests\Unit\Security\Double\RecordingEventLog;
use Liminal\Tests\Unit\Security\Double\RecordingThrottle;
use Liminal\Tests\Unit\Security\Double\RecordingUserProvider;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authenticator::class)]
final class AuthenticatorTest extends TestCase
{
    public function testAnUnknownIdentifierIsRefused(): void
    {
        $session = $this->session();

        $result = $this->authenticator(new NullUserProvider())->attempt('ghost', 'whatever', $session, $this->client());

        self::assertFalse($result->isGranted());
        self::assertNull($session->userId());
    }

    public function testTheWrongPasswordIsRefused(): void
    {
        $session = $this->session();

        $result = $this->authenticator($this->provider())->attempt('alice', 'wrong', $session, $this->client());

        self::assertFalse($result->isGranted());
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

        self::assertFalse($authenticator->attempt('alice', 'secret', $session, $this->client())->isGranted());
        self::assertNull($session->userId());
    }

    public function testASuccessfulLoginRegeneratesTheSessionAndStoresTheUser(): void
    {
        $session = $this->session();
        $previousId = $session->id();

        $result = $this->authenticator($this->provider())->attempt('alice', 'secret', $session, $this->client());

        self::assertTrue($result->isGranted());
        self::assertNotNull($result->user);
        self::assertSame(7, $result->user->id());
        self::assertSame(7, $session->userId());
        // Anti-fixation: whatever id existed before the login is dead.
        self::assertNotSame($previousId, $session->id());
        self::assertTrue($session->isNew());
    }

    /**
     * A locked identifier must not buy an attacker 250ms of bcrypt CPU per
     * try: the throttle is consulted before the user is even looked up.
     */
    public function testAThrottledAttemptNeverReachesTheUserLookup(): void
    {
        $provider = $this->provider();
        $provider->refuseLookups = true;

        $result = $this->authenticator($provider, new RecordingThrottle(retryAfter: 42))
            ->attempt('alice', 'secret', $this->session(), $this->client());

        self::assertTrue($result->wasThrottled());
        self::assertSame(42, $result->retryAfterSeconds);
    }

    public function testEveryOutcomeRecordsExactlyOneEvent(): void
    {
        $log = new RecordingEventLog();

        $this->authenticator($this->provider(), events: $log)
            ->attempt('alice', 'wrong', $this->session(), $this->client());
        $this->authenticator($this->provider(), events: $log)
            ->attempt('alice', 'secret', $this->session(), $this->client());
        $this->authenticator($this->provider(), new RecordingThrottle(retryAfter: 5), $log)
            ->attempt('alice', 'secret', $this->session(), $this->client());

        self::assertSame(
            [AuthEvent::LoginRefused, AuthEvent::LoginGranted, AuthEvent::LoginThrottled],
            $log->events,
        );
    }

    public function testARefusalCountsAndASuccessClearsTheIdentifier(): void
    {
        $throttle = new RecordingThrottle();

        $this->authenticator($this->provider(), $throttle)
            ->attempt('alice', 'wrong', $this->session(), $this->client());

        self::assertSame(['failure:alice'], $throttle->calls);

        $this->authenticator($this->provider(), $throttle)
            ->attempt('alice', 'secret', $this->session(), $this->client());

        self::assertSame(['failure:alice', 'success:alice'], $throttle->calls);
    }

    /**
     * Rehash-on-login writes back only on the successful path, so a refused
     * attempt never costs a write.
     */
    public function testOnlyASuccessfulLoginRehashesAnOutdatedHash(): void
    {
        // A cost-4 digest is outdated against PASSWORD_DEFAULT's cost 12.
        $provider = $this->provider(hash: password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]));

        $this->authenticator($provider)->attempt('alice', 'wrong', $this->session(), $this->client());

        self::assertNull($provider->rehashed);

        $this->authenticator($provider)->attempt('alice', 'secret', $this->session(), $this->client());

        self::assertIsString($provider->rehashed);
        self::assertFalse(new PasswordHasher()->needsRehash($provider->rehashed));
    }

    public function testAnUpToDateHashIsLeftAlone(): void
    {
        $provider = $this->provider();

        $this->authenticator($provider)->attempt('alice', 'secret', $this->session(), $this->client());

        self::assertNull($provider->rehashed);
    }

    public function testLogoutClearsAndRegenerates(): void
    {
        $session = $this->session();
        $authenticator = $this->authenticator($this->provider());
        $authenticator->attempt('alice', 'secret', $session, $this->client());

        $loggedInId = $session->id();
        $session->set('leftover', 'data');

        $authenticator->logout($session, $this->client());

        self::assertNull($session->userId());
        self::assertNull($session->companyId());
        self::assertNull($session->get('leftover'));
        self::assertNotSame($loggedInId, $session->id());
    }

    /**
     * @param list<int> $companies
     */
    private function provider(array $companies = [1], ?string $hash = null): RecordingUserProvider
    {
        $user = new class ($companies) implements AuthenticatedUser {
            /** @param list<int> $companies */
            public function __construct(private readonly array $companies) {}

            public function id(): int
            {
                return 7;
            }

            public function displayName(): string
            {
                return 'Fixture user';
            }

            public function accessibleCompanyIds(): array
            {
                return $this->companies;
            }
        };

        return new RecordingUserProvider($user, $hash ?? new PasswordHasher()->hash('secret'));
    }

    private function authenticator(
        UserProvider $users,
        ?LoginThrottle $throttle = null,
        ?AuthEventLog $events = null,
    ): Authenticator {
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

        return new Authenticator(
            $users,
            new PasswordHasher(),
            $manager,
            $throttle ?? new NullLoginThrottle(),
            $events ?? new NullAuthEventLog(),
        );
    }

    private function session(): Session
    {
        return Session::fresh('a-fresh-session-id', new DateTimeImmutable());
    }

    private function client(): ClientContext
    {
        return new ClientContext('203.0.113.7', 'PHPUnit');
    }
}
