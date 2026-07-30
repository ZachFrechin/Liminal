<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-company module gating through real HTTP: a module's protected routes
 * only answer for companies the module is enabled for, a disabled one is
 * indistinguishable from a route that does not exist, and its PUBLIC routes
 * are never gated at all — gating needs a company, and a public route is
 * pre-authentication.
 */
#[CoversNothing]
final class ModuleGateTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';
    private const COOKIE = 'liminal';
    private const COMPANY = 1;

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping module gate test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $runner = new Kernel(self::ROOT)->container()->get(MigrationRunner::class);
        self::assertInstanceOf(MigrationRunner::class, $runner);
        $runner->migrateToLatest();

        $this->dbal->insert('core_company', [
            'id' => self::COMPANY,
            'code' => 'MAIN',
            'name' => 'MAIN',
            'created_at' => '2026-07-29 00:00:00',
            'updated_at' => '2026-07-29 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    /**
     * Not merely refused — indistinguishable. A 403 would confirm the module
     * exists but is off, which is free reconnaissance in a multi-tenant ERP.
     */
    public function testAProtectedRouteOfADisabledModuleIsIndistinguishableFrom404(): void
    {
        $kernel = $this->kernel();
        $cookie = $this->login($kernel);

        $gated = $kernel->handle($this->get('/gated', $cookie));
        $nonexistent = $kernel->handle($this->get('/no-such-path', $cookie));

        self::assertSame(404, $gated->getStatusCode());
        self::assertSame(404, $nonexistent->getStatusCode());
        // Same status, same envelope: only the echoed path differs, exactly as
        // a genuinely unrouted path would read.
        self::assertSame(
            str_replace('/no-such-path', '/gated', (string) $nonexistent->getBody()),
            (string) $gated->getBody(),
        );
    }

    /**
     * The chicken-and-egg guard: a module's login-style page must answer before
     * anyone can possibly enable the module for their company — and even when
     * an operator disabled it.
     */
    public function testAPublicRouteOfADisabledModuleStillAnswers(): void
    {
        $response = $this->kernel()->handle($this->get('/gated-public'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"module":"gated"', (string) $response->getBody());
    }

    public function testEnablingTheModulePerCompanyOpensTheProtectedRoute(): void
    {
        $manager = $this->kernel()->container()->get(ModuleManager::class);

        self::assertInstanceOf(ModuleManager::class, $manager);

        $manager->install('gated');
        $manager->enable('gated', self::COMPANY);

        $kernel = $this->kernel();
        $response = $kernel->handle($this->get('/gated', $this->login($kernel)));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"module":"gated"', (string) $response->getBody());
    }

    public function testDisablingItAgainClosesTheProtectedRoute(): void
    {
        $manager = $this->kernel()->container()->get(ModuleManager::class);

        self::assertInstanceOf(ModuleManager::class, $manager);

        $manager->install('gated');
        $manager->enable('gated', self::COMPANY);
        $manager->disable('gated', self::COMPANY);

        $kernel = $this->kernel();

        self::assertSame(404, $kernel->handle($this->get('/gated', $this->login($kernel)))->getStatusCode());
    }

    /**
     * Library routes carry a prefix no declared module owns, so the gate never
     * looks at them — the health endpoint answers whatever the module state is.
     */
    public function testLibRoutesAreNeverGated(): void
    {
        self::assertSame(200, $this->kernel()->handle($this->get('/'))->getStatusCode());
    }

    private function kernel(): Kernel
    {
        return new Kernel(self::ROOT);
    }

    private function login(Kernel $kernel): string
    {
        $tokenResponse = $kernel->handle($this->get('/token'));
        $body = json_decode((string) $tokenResponse->getBody(), true);
        $token = is_array($body) && is_string($body['token'] ?? null) ? $body['token'] : '';
        $anonymous = $this->cookieValue($tokenResponse);

        $login = $kernel->handle(
            new Psr17Factory()->createServerRequest('POST', '/login')
                ->withParsedBody(['identifier' => 'alice', 'password' => 'alice-secret', '_token' => $token])
                ->withCookieParams([self::COOKIE => $anonymous]),
        );

        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());

        return $this->cookieValue($login);
    }

    private function get(string $path, ?string $cookie = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', $path);

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
