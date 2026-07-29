<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Database;

use Doctrine\DBAL\Connection;
use Liminal\Config\Configuration;
use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatus;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseHealth::class)]
#[CoversClass(DatabaseStatus::class)]
final class DatabaseHealthTest extends TestCase
{
    /**
     * The whole point of the deferred closure: an unconfigured DSN is decided
     * before any connection work, so it can never crash anything.
     */
    public function testAnUnconfiguredDsnIsReportedNotResolved(): void
    {
        $health = new DatabaseHealth(new Configuration([]), static function (): Connection {
            self::fail('The connection closure must not run when no DSN is configured.');
        });

        self::assertSame(DatabaseStatusKind::NotConfigured, $health->check()->kind);
    }

    public function testAFailingConnectionIsReportedUnreachable(): void
    {
        $health = new DatabaseHealth(
            new Configuration(['database' => ['url' => 'mysql://nope']]),
            static fn(): Connection => throw new RuntimeException('connection refused'),
        );

        $status = $health->check();

        self::assertSame(DatabaseStatusKind::Unreachable, $status->kind);
        self::assertSame('connection refused', $status->detail);
    }

    public function testAWorkingConnectionReportsTheServerVersion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('11.4.2-MariaDB');

        $health = new DatabaseHealth(
            new Configuration(['database' => ['url' => 'mysql://ok']]),
            static fn(): Connection => $connection,
        );

        $status = $health->check();

        self::assertSame(DatabaseStatusKind::Ok, $status->kind);
        self::assertSame('11.4.2-MariaDB', $status->detail);
    }
}
