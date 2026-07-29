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
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-company module gating through real HTTP: a module's route only answers
 * for companies the module is enabled for, and a disabled one is
 * indistinguishable from a route that does not exist.
 */
#[CoversNothing]
final class ModuleGateTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';
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
    public function testARouteOfADisabledModuleIsIndistinguishableFrom404(): void
    {
        $kernel = $this->kernel();

        $gated = $kernel->handle($this->get('/gated'));
        $nonexistent = $kernel->handle($this->get('/no-such-path'));

        self::assertSame(404, $gated->getStatusCode());
        self::assertSame(404, $nonexistent->getStatusCode());
        self::assertSame($nonexistent->getHeaders(), $gated->getHeaders());
        // Same status, same envelope: only the echoed path differs, exactly as
        // a genuinely unrouted path would read.
        self::assertSame(
            str_replace('/no-such-path', '/gated', (string) $nonexistent->getBody()),
            (string) $gated->getBody(),
        );
    }

    public function testEnablingTheModulePerCompanyOpensTheRoute(): void
    {
        $manager = $this->kernel()->container()->get(ModuleManager::class);

        self::assertInstanceOf(ModuleManager::class, $manager);

        $manager->install('gated');
        $manager->enable('gated', self::COMPANY);

        $response = $this->kernel()->handle($this->get('/gated'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"module":"gated"', (string) $response->getBody());
    }

    public function testDisablingItAgainClosesTheRoute(): void
    {
        $manager = $this->kernel()->container()->get(ModuleManager::class);

        self::assertInstanceOf(ModuleManager::class, $manager);

        $manager->install('gated');
        $manager->enable('gated', self::COMPANY);
        $manager->disable('gated', self::COMPANY);

        self::assertSame(404, $this->kernel()->handle($this->get('/gated'))->getStatusCode());
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

    private function get(string $path): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', $path);
    }
}
