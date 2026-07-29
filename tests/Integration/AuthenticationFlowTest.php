<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Deny-by-default through real HTTP: full kernel boot on the security fixture
 * root, whose contributor overrides the UserProvider through the same
 * definition layering the phase-5 authentication module will use.
 *
 * Nyholm does not parse Cookie headers into cookie params, so these flows
 * read Set-Cookie back and feed withCookieParams() by hand.
 */
#[CoversNothing]
final class AuthenticationFlowTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';
    private const COOKIE = 'liminal';

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping authentication flow test.');
        }

        $this->dbal = $this->connection();

        $tables = ['test_widget', 'test_gadget', 'core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'];

        foreach ($tables as $table) {
            $this->dbal->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        // Boot one throwaway kernel to migrate and seed the companies the
        // fixture users reference.
        $container = new Kernel(self::ROOT)->container();
        $runner = $container->get(\Liminal\Lib\Database\Migration\MigrationRunner::class);
        self::assertInstanceOf(\Liminal\Lib\Database\Migration\MigrationRunner::class, $runner);
        $runner->migrateToLatest();

        foreach ([['MAIN', 1], ['SECOND', 2]] as [$code, $id]) {
            $this->dbal->insert('core_company', [
                'id' => $id,
                'code' => $code,
                'name' => $code,
                'created_at' => '2026-07-29 00:00:00',
                'updated_at' => '2026-07-29 00:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testAnonymousRequestsToProtectedRoutesAre401Json(): void
    {
        $response = $this->kernel()->handle($this->get('/me'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Authentication required', (string) $response->getBody());
    }

    public function testSystemHealthStaysPublic(): void
    {
        self::assertSame(200, $this->kernel()->handle($this->get('/'))->getStatusCode());
    }

    public function testLoginGrantsAccessAndTheCookieRoundTrips(): void
    {
        $kernel = $this->kernel();

        $login = $kernel->handle($this->post('/login', ['identifier' => 'alice', 'password' => 'alice-secret']));

        self::assertSame(200, $login->getStatusCode());

        $cookie = $this->cookieValue($login);
        $me = $kernel->handle($this->get('/me', $cookie));

        self::assertSame(200, $me->getStatusCode());
        self::assertStringContainsString('"user":7', (string) $me->getBody());
    }

    public function testTheWrongPasswordRespondsWithoutASession(): void
    {
        $response = $this->kernel()->handle($this->post('/login', ['identifier' => 'alice', 'password' => 'nope']));

        self::assertSame(400, $response->getStatusCode());
        // Failure responds (never throws) AND leaves nothing behind: no row,
        // no cookie.
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
    }

    public function testLoginRegeneratesTheSessionIdAndTheOldIdDies(): void
    {
        $kernel = $this->kernel();

        $first = $kernel->handle($this->post('/login', ['identifier' => 'alice', 'password' => 'alice-secret']));
        $firstCookie = $this->cookieValue($first);

        // Logging in AGAIN with the first session's cookie regenerates it.
        $second = $kernel->handle(
            $this->post('/login', ['identifier' => 'alice', 'password' => 'alice-secret'], $firstCookie),
        );
        $secondCookie = $this->cookieValue($second);

        self::assertNotSame($firstCookie, $secondCookie);
        self::assertSame(401, $kernel->handle($this->get('/me', $firstCookie))->getStatusCode());
        self::assertSame(200, $kernel->handle($this->get('/me', $secondCookie))->getStatusCode());
    }

    /**
     * A session pointing at a user the provider no longer knows must log out
     * cleanly — deletion is a data state, never a 500.
     */
    public function testADeletedUserIsLoggedOutNotServerError(): void
    {
        $rawId = str_repeat('a', 43);
        $now = new DateTimeImmutable();

        $this->dbal->insert('core_session', [
            'id' => hash('sha256', $rawId),
            'user_id' => 999,
            'company_id' => null,
            'payload' => '{}',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'last_seen_at' => $now->modify('-10 minutes')->format('Y-m-d H:i:s'),
            'expires_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s'),
        ]);

        $response = $this->kernel()->handle($this->get('/me', $rawId));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLogoutInvalidatesTheServerSideSession(): void
    {
        $kernel = $this->kernel();

        $login = $kernel->handle($this->post('/login', ['identifier' => 'alice', 'password' => 'alice-secret']));
        $cookie = $this->cookieValue($login);

        $logout = $kernel->handle($this->post('/logout', [], $cookie));

        self::assertSame(200, $logout->getStatusCode());
        self::assertSame(401, $kernel->handle($this->get('/me', $cookie))->getStatusCode());
    }

    private function kernel(): Kernel
    {
        return new Kernel(self::ROOT);
    }

    private function get(string $path, ?string $cookie = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', $path);

        return $cookie === null ? $request : $request->withCookieParams([self::COOKIE => $cookie]);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body, ?string $cookie = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('POST', $path)->withParsedBody($body);

        return $cookie === null ? $request : $request->withCookieParams([self::COOKIE => $cookie]);
    }

    private function cookieValue(ResponseInterface $response): string
    {
        $header = $response->getHeaderLine('Set-Cookie');

        if (preg_match('/^' . self::COOKIE . '=([^;]*)/', $header, $matches) !== 1) {
            self::fail(sprintf('No session cookie in the response; got "%s".', $header));
        }

        return $matches[1];
    }
}
