<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Hook;

use Closure;
use Liminal\Lib\Hook\Contract\TriggerScope;
use Liminal\Lib\Hook\Exception\HookException;
use Liminal\Lib\Hook\NullTriggerScope;
use Liminal\Lib\Hook\Triggers;
use Liminal\Registry\TriggerRegistry;
use Liminal\Tests\Unit\Hook\Double\FailingTriggerListener;
use Liminal\Tests\Unit\Hook\Double\RecordingTriggerListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use stdClass;
use Stringable;

#[CoversClass(Triggers::class)]
final class TriggersTest extends TestCase
{
    public function testTheEventReachesEveryListenerEnriched(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('USER_CREATED');
        $registry->listen('USER_CREATED', 'recorder');

        $recorder = new RecordingTriggerListener();

        $scope = new class implements TriggerScope {
            public ?int $actor = 42;

            public function actorId(): ?int
            {
                return $this->actor;
            }

            public function companyId(): int
            {
                return 3;
            }
        };

        $triggers = new Triggers($registry, $this->resolverOver(['recorder' => $recorder]), $scope, new NullLogger());
        $triggers->fire('USER_CREATED', ['user_id' => 7, 'email' => 'ada@liminal.test']);

        self::assertCount(1, $recorder->events);
        $event = $recorder->events[0];
        self::assertSame('USER_CREATED', $event->name);
        self::assertSame(['user_id' => 7, 'email' => 'ada@liminal.test'], $event->payload);
        self::assertSame(42, $event->actorId);
        self::assertSame(3, $event->companyId);
    }

    /**
     * The fire point may know the company better than the working context —
     * console commands do — and the explicit argument wins.
     */
    public function testAnExplicitCompanyOverridesTheScope(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('GRANT_ADDED');
        $registry->listen('GRANT_ADDED', 'recorder');

        $recorder = new RecordingTriggerListener();
        $triggers = new Triggers($registry, $this->resolverOver(['recorder' => $recorder]), new NullTriggerScope(), new NullLogger());

        $triggers->fire('GRANT_ADDED', ['user_id' => 7], companyId: 2);

        self::assertSame(2, $recorder->events[0]->companyId);
        // And the null scope answers honestly when nothing overrides.
        self::assertNull($recorder->events[0]->actorId);
    }

    /**
     * The trigger contract with teeth: a listener failure — miswiring
     * included — is caught and logged, and the NEXT listener still runs.
     */
    public function testAFailingListenerIsCaughtLoggedAndTheNextOneRuns(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('USER_CREATED');
        $registry->listen('USER_CREATED', 'boom', priority: 10);
        $registry->listen('USER_CREATED', 'miswired', priority: 20);
        $registry->listen('USER_CREATED', 'survivor', priority: 30);

        $recorder = new RecordingTriggerListener();
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, listener: mixed}> */
            public array $errors = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->errors[] = ['message' => (string) $message, 'listener' => $context['listener'] ?? null];
            }
        };

        $triggers = new Triggers(
            $registry,
            $this->resolverOver([
                'boom' => new FailingTriggerListener(),
                'miswired' => new stdClass(),
                'survivor' => $recorder,
            ]),
            new NullTriggerScope(),
            $logger,
        );

        $triggers->fire('USER_CREATED', ['user_id' => 1]);

        // Both failures logged, each naming its listener; the survivor heard it.
        self::assertCount(2, $logger->errors);
        self::assertSame('boom', $logger->errors[0]['listener']);
        self::assertSame('miswired', $logger->errors[1]['listener']);
        self::assertCount(1, $recorder->events);
    }

    public function testFiringAnUndeclaredTriggerIsWiringAndThrows(): void
    {
        $triggers = new Triggers(new TriggerRegistry(), $this->resolverOver([]), new NullTriggerScope(), new NullLogger());

        $this->expectException(HookException::class);

        $triggers->fire('GHOST_EVENT');
    }

    public function testACatchAllHearsEverything(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('USER_CREATED');
        $registry->declare('ROLE_DELETED');
        $registry->listenToAll('audit', priority: -1000);

        $audit = new RecordingTriggerListener();
        $triggers = new Triggers($registry, $this->resolverOver(['audit' => $audit]), new NullTriggerScope(), new NullLogger());

        $triggers->fire('USER_CREATED');
        $triggers->fire('ROLE_DELETED');

        self::assertSame(
            ['USER_CREATED', 'ROLE_DELETED'],
            array_map(static fn($event): string => $event->name, $audit->events),
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
