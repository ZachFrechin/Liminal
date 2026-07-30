<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\DatabaseContributor;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Security\SecurityContributor;
use Liminal\Lib\Security\Session\SessionManager;
use Liminal\Registry\MigrationRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Session storage against real MariaDB: both OWASP timeouts, the write
 * economy, and the two properties that make the design safe — deletion beats
 * concurrent writers, and nothing is written for traffic that never engaged.
 */
#[CoversNothing]
final class SessionLifecycleTest extends IntegrationTestCase
{
    private const COOKIE = 'liminal';

    private Connection $dbal;

    protected function setUp(): void
    {
        $this->dbal = $this->connection();

        $tables = ['core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'];

        foreach ($tables as $table) {
            $this->dbal->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $migrations = new MigrationRegistry();
        $migrations->add(DatabaseContributor::MIGRATION_NAMESPACE, dirname(__DIR__, 2) . '/libs/Database/Migrations');
        $migrations->add(SecurityContributor::MIGRATION_NAMESPACE, dirname(__DIR__, 2) . '/libs/Security/Migrations');

        $connection = $this->dbal;
        new MigrationRunner(new MigrationFactory($migrations), $migrations, static fn(): Connection => $connection)
            ->migrateToLatest();
    }

    public function testAnUntouchedSessionLeavesNoRowAndNoCookie(): void
    {
        $manager = $this->manager();
        $response = $manager->persist($manager->start($this->request()), $this->response());

        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
    }

    public function testAWrittenSessionRoundTripsThroughItsCookie(): void
    {
        $manager = $this->manager();

        $first = $manager->start($this->request());
        $first->set('greeting', 'hello');
        $response = $manager->persist($first, $this->response());

        $id = $this->cookieValue($response);

        self::assertNotSame('', $id);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
        // Only the digest is stored: the dump cannot be replayed.
        self::assertEquals(
            hash('sha256', $id),
            $this->dbal->fetchOne('SELECT id FROM core_session'),
        );

        $second = $manager->start($this->request($id));

        self::assertFalse($second->isNew());
        self::assertSame('hello', $second->get('greeting'));
    }

    public function testAReadOnlyRequestWithinTheTouchWindowWritesNothing(): void
    {
        $manager = $this->manager();
        $id = $this->existingSession($manager, ['greeting' => 'hello']);

        $before = $this->dbal->fetchOne('SELECT last_seen_at FROM core_session');

        $session = $manager->start($this->request($id));

        self::assertSame('hello', $session->get('greeting'));

        $manager->persist($session, $this->response());

        self::assertEquals($before, $this->dbal->fetchOne('SELECT last_seen_at FROM core_session'));
    }

    public function testAnIdleSessionIsRefusedAfterItsTtl(): void
    {
        $manager = $this->manager(idleTtl: 1);
        $id = $this->existingSession($manager, ['greeting' => 'hello']);

        $this->expireIn('-1 second');

        self::assertTrue($manager->start($this->request($id))->isNew());
    }

    /**
     * The absolute cap is counted from creation, so even a session that keeps
     * being used dies — the second OWASP timeout, from the same column.
     */
    public function testTheAbsoluteCapEndsEvenAnActiveSession(): void
    {
        $manager = $this->manager(idleTtl: 7200, absoluteTtl: 1);
        $id = $this->existingSession($manager, ['greeting' => 'hello']);

        $expires = $this->dbal->fetchOne('SELECT expires_at FROM core_session');

        self::assertIsString($expires);
        self::assertLessThanOrEqual(new DateTimeImmutable('+2 seconds')->format('Y-m-d H:i:s'), $expires);

        $this->expireIn('-1 second');

        self::assertTrue($manager->start($this->request($id))->isNew());
    }

    public function testRegenerationMovesThePayloadToANewIdAndKillsTheOld(): void
    {
        $manager = $this->manager();
        $id = $this->existingSession($manager, ['greeting' => 'hello']);

        $session = $manager->start($this->request($id));
        $manager->regenerate($session);
        $response = $manager->persist($session, $this->response());

        $newId = $this->cookieValue($response);

        self::assertNotSame($id, $newId);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
        self::assertTrue($manager->start($this->request($id))->isNew());
        self::assertSame('hello', $manager->start($this->request($newId))->get('greeting'));
    }

    /**
     * The regression test for the race the design exists to close: a parallel
     * request holding the pre-regeneration cookie must NOT be able to write
     * its row back, or the retired id would be valid again.
     */
    public function testAStaleParallelRequestCannotResurrectARegeneratedSession(): void
    {
        $manager = $this->manager();
        $id = $this->existingSession($manager, ['greeting' => 'hello']);

        // The parallel request started before the login.
        $parallel = $manager->start($this->request($id));

        $logging = $manager->start($this->request($id));
        $manager->regenerate($logging);
        $manager->persist($logging, $this->response());

        // It writes after the regeneration — and must vanish quietly.
        $parallel->set('greeting', 'from the past');
        $manager->persist($parallel, $this->response());

        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
        self::assertEquals(
            0,
            $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session WHERE id = ?', [hash('sha256', $id)]),
        );
    }

    /**
     * A response that hands out (or expires) a session cookie must never be
     * cached by any intermediary: the cookie is the credential.
     */
    public function testACookieIssuingResponseCarriesCacheControlNoStore(): void
    {
        $manager = $this->manager();

        $session = $manager->start($this->request());
        $session->set('greeting', 'hello');
        $response = $manager->persist($session, $this->response());

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testDestroyingASessionRemovesTheRowAndExpiresTheCookie(): void
    {
        $manager = $this->manager();
        $id = $this->existingSession($manager, ['greeting' => 'hello']);

        $session = $manager->start($this->request($id));
        $manager->destroy($session);
        $response = $manager->persist($session, $this->response());

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    public function testGarbageCollectionDeletesOnlyExpiredRows(): void
    {
        $manager = $this->manager();
        $this->existingSession($manager, ['keep' => 'me']);
        $expiredId = $this->existingSession($manager, ['drop' => 'me']);

        $this->dbal->executeStatement(
            'UPDATE core_session SET expires_at = ? WHERE id = ?',
            [new DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'), hash('sha256', $expiredId)],
        );

        self::assertSame(1, $manager->gc());
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
    }

    private function manager(int $idleTtl = 7200, int $absoluteTtl = 43200): SessionManager
    {
        $connection = $this->dbal;

        return new SessionManager(
            static fn(): Connection => $connection,
            self::COOKIE,
            $idleTtl,
            $absoluteTtl,
            false,
            0,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string the raw cookie value of the stored session
     */
    private function existingSession(SessionManager $manager, array $payload): string
    {
        $session = $manager->start($this->request());

        foreach ($payload as $key => $value) {
            $session->set($key, $value);
        }

        return $this->cookieValue($manager->persist($session, $this->response()));
    }

    private function request(?string $cookie = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', '/');

        return $cookie === null ? $request : $request->withCookieParams([self::COOKIE => $cookie]);
    }

    private function response(): ResponseInterface
    {
        return new Psr17Factory()->createResponse(200);
    }

    /**
     * Nyholm does not parse a Cookie header into cookie params, so tests must
     * read the value back out of Set-Cookie and hand it to withCookieParams().
     */
    private function cookieValue(ResponseInterface $response): string
    {
        $header = $response->getHeaderLine('Set-Cookie');

        if (preg_match('/^' . self::COOKIE . '=([^;]*)/', $header, $matches) !== 1) {
            self::fail(sprintf('No session cookie in the response; got "%s".', $header));
        }

        return $matches[1];
    }

    private function expireIn(string $modifier): void
    {
        $this->dbal->executeStatement(
            'UPDATE core_session SET expires_at = ?',
            [new DateTimeImmutable($modifier)->format('Y-m-d H:i:s')],
        );
    }
}
