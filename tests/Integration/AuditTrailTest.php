<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Closure;
use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Hook\Contract\TriggerScope;
use Liminal\Lib\Hook\NullTriggerScope;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Audit\AuditTrailListener;
use Liminal\Lib\Security\Audit\AuthenticatedTriggerScope;
use Liminal\Registry\TriggerRegistry;
use Liminal\Support\Env;
use Liminal\Tests\Unit\Hook\Double\FailingTriggerListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The audit trail against real SQL: one fired trigger becomes one append-only
 * row, whatever the other listeners do — including the ones that explode on
 * either side of the anchor priority.
 */
#[CoversNothing]
final class AuditTrailTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping audit trail test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $runner = new Kernel(self::ROOT)->container()->get(MigrationRunner::class);
        self::assertInstanceOf(MigrationRunner::class, $runner);
        $runner->migrateToLatest();
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testAFiredTriggerBecomesOneRowAndThePayloadRoundTrips(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('USER_CREATED');
        $registry->listenToAll('audit', priority: -1000);

        $triggers = $this->triggersOver($registry, ['audit' => $this->listener()]);
        $triggers->fire('USER_CREATED', ['user_id' => 7, 'email' => 'ada@liminal.test', 'note' => null], companyId: 2);

        $row = $this->dbal->fetchAssociative('SELECT * FROM core_audit_event');

        self::assertNotFalse($row);
        self::assertSame('USER_CREATED', $row['event']);
        self::assertNull($row['actor_id']);
        self::assertEquals(2, $row['company_id']);
        self::assertIsString($row['payload']);
        // The payload survives the JSON round trip exactly.
        self::assertSame(
            ['user_id' => 7, 'email' => 'ada@liminal.test', 'note' => null],
            json_decode($row['payload'], true),
        );
        self::assertIsString($row['occurred_at']);
    }

    /**
     * The anchor holds from both sides: a listener exploding BEFORE the audit
     * (priority -2000) cannot stop the row, and one exploding after changes
     * nothing — every failure logged, every remaining listener run.
     */
    public function testBrokenListenersOnEitherSideOfTheAnchorLeaveTheAuditRow(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('ROLE_DELETED');
        $registry->listen('ROLE_DELETED', 'before-anchor', priority: -2000);
        $registry->listenToAll('audit', priority: -1000);
        $registry->listen('ROLE_DELETED', 'after-anchor', priority: 100);

        $logged = [];
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $seen */
            public function __construct(public array &$seen) {}

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $listener = $context['listener'] ?? null;
                $this->seen[] = is_string($listener) ? $listener : '?';
            }
        };

        $triggers = new Triggers(
            $registry,
            $this->resolverOver([
                'before-anchor' => new FailingTriggerListener(),
                'audit' => $this->listener(),
                'after-anchor' => new FailingTriggerListener(),
            ]),
            new NullTriggerScope(),
            $logger,
        );

        $triggers->fire('ROLE_DELETED', ['role_id' => 3]);

        self::assertSame(['before-anchor', 'after-anchor'], $logged);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_audit_event'));
    }

    /**
     * The real wiring on the real root: the scope resolves to the
     * authenticated adapter, the dispatcher resolves console-safely, and the
     * doctor announces the new registries.
     */
    public function testTheRealRootWiresTheScopeTheDispatcherAndTheDoctor(): void
    {
        $container = new Kernel(self::ROOT)->container();

        self::assertInstanceOf(AuthenticatedTriggerScope::class, $container->get(TriggerScope::class));
        self::assertInstanceOf(Triggers::class, $container->get(Triggers::class));

        $doctor = $container->get(\Liminal\Lib\System\Console\DoctorCommand::class);
        self::assertInstanceOf(\Liminal\Lib\System\Console\DoctorCommand::class, $doctor);

        $tester = new \Symfony\Component\Console\Tester\CommandTester($doctor);
        $tester->execute([]);

        self::assertStringContainsString('hooks declared', $tester->getDisplay());
        self::assertStringContainsString('triggers declared', $tester->getDisplay());
    }

    private function listener(): AuditTrailListener
    {
        return new AuditTrailListener(fn(): Connection => $this->dbal);
    }

    /**
     * @param array<string, object> $services
     */
    private function triggersOver(TriggerRegistry $registry, array $services): Triggers
    {
        return new Triggers(
            $registry,
            $this->resolverOver($services),
            new NullTriggerScope(),
            new \Psr\Log\NullLogger(),
        );
    }

    /**
     * @param array<string, object> $services
     *
     * @return Closure(string): object
     */
    private function resolverOver(array $services): Closure
    {
        return static fn(string $id): object => $services[$id];
    }
}
