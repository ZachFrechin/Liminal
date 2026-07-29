<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Smoke test proving the CI service container and the local DSN convention agree.
 */
#[CoversNothing]
final class DatabaseConnectionTest extends IntegrationTestCase
{
    public function testTheConfiguredDatabaseAnswers(): void
    {
        $result = $this->connection()->executeQuery('SELECT 1 AS one')->fetchAssociative();

        self::assertSame('1', (string) ($result['one'] ?? null));
    }
}
