<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ServerRequestInterface;

/**
 * How refusals reach a browser: HTML pages and the login redirect, without
 * ever changing what a JSON client sees.
 */
#[CoversNothing]
final class HtmlErrorPagesTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping html error page test.');
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

    public function testABrowser404GetsAnHtmlErrorPage(): void
    {
        $response = new Kernel(self::ROOT)->handle($this->browserGet('/no-such-path'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $html = (string) $response->getBody();

        self::assertStringContainsString('<h1>404</h1>', $html);
        // The translated heading proves the catalogue resolved through Twig.
        self::assertStringContainsString('Something went wrong', $html);
    }

    public function testABrowser401RedirectsToTheDeclaredLoginRouteCarryingTheIntendedPath(): void
    {
        $response = new Kernel(self::ROOT)->handle($this->browserGet('/page'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?redirect=%2Fpage', $response->getHeaderLine('Location'));
    }

    /**
     * The JSON contract predates rendering and must survive it untouched.
     */
    public function testAJsonClient401StaysJson(): void
    {
        $response = new Kernel(self::ROOT)->handle(
            new Psr17Factory()->createServerRequest('GET', '/page')->withHeader('Accept', 'application/json'),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * A template that explodes must not be dressed up as an error page: the
     * outer handler logs it and answers 500, which is the honest signal that
     * templates are broken.
     */
    public function testABrokenTemplateFallsBackToTheOuterJsonHandler(): void
    {
        $response = new Kernel(self::ROOT)->handle($this->browserGet('/broken'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    private function browserGet(string $path): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', $path)
            ->withHeader('Accept', 'text/html,application/xhtml+xml');
    }
}
