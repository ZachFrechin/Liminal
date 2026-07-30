<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The feedback family through the real stack: one anonymous fixture page
 * renders every component — strict_variables makes any macro drift a hard
 * 500, so a 200 with the pinned markup IS the proof.
 */
#[CoversNothing]
final class DesignComponentsTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping design components test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

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

    public function testEveryFeedbackComponentRendersThroughTheRealLayout(): void
    {
        $response = new Kernel(self::ROOT)->handle(
            new Psr17Factory()->createServerRequest('GET', '/design'),
        );

        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getBody();

        // Banners: four tones, danger alone assertive, glyph inline.
        foreach (['info', 'success', 'warning'] as $tone) {
            self::assertStringContainsString(
                sprintf('<div class="banner banner-%s" role="status">', $tone),
                $html,
            );
        }
        self::assertStringContainsString('<div class="banner banner-danger" role="alert">', $html);
        self::assertStringContainsString('<p class="banner-message">Something failed.</p>', $html);

        // Empty state: icon chip, contiguous title, description.
        self::assertStringContainsString('<strong class="empty-state-title">Nothing here yet</strong>', $html);
        self::assertStringContainsString('class="empty-state-icon"', $html);

        // Toast, tooltip, dialog: the documented patterns render whole.
        self::assertStringContainsString('<div class="toast toast-success" role="status">', $html);
        self::assertStringContainsString('<span class="tooltip-bubble" role="tooltip">', $html);
        self::assertStringContainsString('<div class="dialog dialog-sm">', $html);
        self::assertStringContainsString('<footer class="dialog-footer">', $html);

        // Every icon is the vendored inline SVG, decorative by default.
        self::assertStringContainsString('<svg class="lucide"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }
}
