<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Hook;

use Closure;
use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\Exception\HookException;
use Liminal\Lib\Hook\HookContext;
use Liminal\Lib\Hook\Hooks;
use Liminal\Registry\HookRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Hooks::class)]
final class HooksTest extends TestCase
{
    public function testTheValueTravelsListenerToListenerInPriorityOrder(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->listen('invoice.total.compute', 'double', priority: 200);
        $registry->listen('invoice.total.compute', 'add-ten', priority: 100);

        $hooks = new Hooks($registry, $this->resolverOver([
            'add-ten' => new class implements HookListener {
                public function transform(mixed $value, HookContext $context): mixed
                {
                    return (is_int($value) ? $value : 0) + 10;
                }
            },
            'double' => new class implements HookListener {
                public function transform(mixed $value, HookContext $context): mixed
                {
                    return (is_int($value) ? $value : 0) * 2;
                }
            },
        ]));

        // (5 + 10) * 2, never (5 * 2) + 10: priorities decide.
        self::assertSame(30, $hooks->filter('invoice.total.compute', 5));
    }

    public function testTheContextCarriesTheHookNameAndParameters(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->listen('invoice.total.compute', 'inspector');

        $seen = new class implements HookListener {
            public ?HookContext $context = null;

            public function transform(mixed $value, HookContext $context): mixed
            {
                $this->context = $context;

                return $value;
            }
        };

        $hooks = new Hooks($registry, $this->resolverOver(['inspector' => $seen]));
        $hooks->filter('invoice.total.compute', 'x', ['invoice_id' => 7]);

        self::assertNotNull($seen->context);
        self::assertSame('invoice.total.compute', $seen->context->hook);
        self::assertSame(['invoice_id' => 7], $seen->context->parameters);
    }

    /**
     * A hook is business logic: a listener's exception must abort the
     * computation it is part of, not be swallowed.
     */
    public function testAListenerExceptionPropagates(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->listen('invoice.total.compute', 'boom');

        $hooks = new Hooks($registry, $this->resolverOver([
            'boom' => new class implements HookListener {
                public function transform(mixed $value, HookContext $context): mixed
                {
                    throw new LogicException('business rule violated');
                }
            },
        ]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('business rule violated');

        $hooks->filter('invoice.total.compute', 5);
    }

    public function testDispatchingAnUndeclaredHookIsWiringAndThrows(): void
    {
        $hooks = new Hooks(new HookRegistry(), $this->resolverOver([]));

        $this->expectException(HookException::class);

        $hooks->filter('ghost.value.compute', 1);
    }

    public function testAMiswiredListenerThrows(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->listen('invoice.total.compute', 'not-a-listener');

        $hooks = new Hooks($registry, $this->resolverOver(['not-a-listener' => new stdClass()]));

        $this->expectException(HookException::class);
        $this->expectExceptionMessageMatches('/does not implement HookListener/');

        $hooks->filter('invoice.total.compute', 1);
    }

    /**
     * Holding the dispatcher costs nothing: the container is only consulted
     * when a hook actually runs — and only for that hook's listeners.
     */
    public function testResolutionIsLazyPerDispatch(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->declare('order.discount.apply');
        $registry->listen('invoice.total.compute', 'used');
        $registry->listen('order.discount.apply', 'never-resolved');

        $resolved = [];
        $resolver = function (string $id) use (&$resolved): object {
            $resolved[] = $id;

            return new class implements HookListener {
                public function transform(mixed $value, HookContext $context): mixed
                {
                    return $value;
                }
            };
        };

        $hooks = new Hooks($registry, Closure::fromCallable($resolver));

        self::assertSame([], $resolved);

        $hooks->filter('invoice.total.compute', 1);

        self::assertSame(['used'], $resolved);
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
