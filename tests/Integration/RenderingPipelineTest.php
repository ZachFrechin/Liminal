<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The rendering layer through the front door: a real Twig page served by the
 * full kernel stack — session, authentication, scope, gate, template.
 */
#[CoversNothing]
final class RenderingPipelineTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';
    private const COOKIE = 'liminal';

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping rendering pipeline test.');
        }

        $this->dbal = $this->connection();

        $tables = ['test_widget', 'test_gadget', 'core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'];

        foreach ($tables as $table) {
            $this->dbal->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $runner = new Kernel(self::ROOT)->container()->get(MigrationRunner::class);
        self::assertInstanceOf(MigrationRunner::class, $runner);
        $runner->migrateToLatest();

        $this->dbal->insert('core_company', [
            'id' => 1,
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

    public function testAProtectedPageRendersThroughTheFullStack(): void
    {
        $kernel = $this->kernel();
        $cookie = $this->login($kernel);

        $response = $kernel->handle($this->get('/page', $cookie));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $html = (string) $response->getBody();

        self::assertStringContainsString('<title>Fixture page</title>', $html);
        self::assertStringContainsString('<h1>Rendered through the stack</h1>', $html);
    }

    /**
     * The design property working for its living: an anonymous page carrying
     * a form engages the session — rendering csrf_field() is the first write,
     * which is exactly what creates the row and the cookie.
     */
    public function testTheFirstFormRenderCreatesTheSessionRowAndCookie(): void
    {
        $response = $this->kernel()->handle($this->get('/form'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="_token"', (string) $response->getBody());
        self::assertMatchesRegularExpression('/^liminal=/', $response->getHeaderLine('Set-Cookie'));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
    }

    public function testFlashSurvivesExactlyOneRequest(): void
    {
        $kernel = $this->kernel();

        $redirect = $kernel->handle($this->get('/flash'));

        self::assertSame(302, $redirect->getStatusCode());

        $cookie = $this->cookieValue($redirect);

        $first = (string) $kernel->handle($this->get('/form', $cookie))->getBody();
        $second = (string) $kernel->handle($this->get('/form', $cookie))->getBody();

        self::assertStringContainsString('Saved.', $first);
        self::assertStringNotContainsString('Saved.', $second);
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
