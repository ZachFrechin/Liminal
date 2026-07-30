<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\Exception\UndeclaredListenerTargetException;
use Liminal\Registry\HookRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HookRegistry::class)]
final class HookRegistryTest extends TestCase
{
    public function testListenersComeBackInPriorityOrderTiesKeepingRegistration(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->listen('invoice.total.compute', 'Late', priority: 200);
        $registry->listen('invoice.total.compute', 'FirstOfTie');
        $registry->listen('invoice.total.compute', 'Early', priority: -5);
        $registry->listen('invoice.total.compute', 'SecondOfTie');

        self::assertSame(
            ['Early', 'FirstOfTie', 'SecondOfTie', 'Late'],
            $registry->listenersFor('invoice.total.compute'),
        );
    }

    /**
     * The declaring module may contribute AFTER the listening one: the
     * declared-name check waits for freeze, where boot order no longer
     * matters — and still refuses a name that never arrived.
     */
    public function testListeningBeforeTheDeclarationIsFineUntilFreezeJudges(): void
    {
        $registry = new HookRegistry();
        $registry->listen('invoice.total.compute', 'EagerListener');
        $registry->declare('invoice.total.compute');

        $registry->freeze();

        self::assertSame(['EagerListener'], $registry->listenersFor('invoice.total.compute'));
    }

    public function testFreezeRefusesASubscriptionNobodyDeclares(): void
    {
        $registry = new HookRegistry();
        $registry->listen('ghost.value.compute', 'Listener');

        $this->expectException(UndeclaredListenerTargetException::class);
        $this->expectExceptionMessageMatches('/ghost\.value\.compute/');

        $registry->freeze();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'two segments' => ['invoice.compute'];
        yield 'one segment' => ['invoice'];
        yield 'uppercase' => ['Invoice.total.compute'];
        yield 'screaming' => ['INVOICE_VALIDATED'];
        yield 'empty segment' => ['invoice..compute'];
        yield 'leading digit' => ['1nvoice.total.compute'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidNames')]
    public function testTheGrammarRefusesWhatIsNotAHookName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HookRegistry()->declare($name);
    }

    public function testDeclaringTwiceIsTheCollisionThatForcesCoordination(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');

        $this->expectException(DuplicateContributionException::class);

        $registry->declare('invoice.total.compute');
    }

    public function testTheSameListenerCannotSubscribeTwiceToOneHook(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->listen('invoice.total.compute', 'Listener');

        $this->expectException(DuplicateContributionException::class);

        $registry->listen('invoice.total.compute', 'Listener', priority: 5);
    }

    public function testAFrozenRegistryRefusesEverything(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->freeze();

        $this->expectException(FrozenRegistryException::class);

        $registry->listen('invoice.total.compute', 'Listener');
    }

    public function testNamesAndHasReportTheDeclarations(): void
    {
        $registry = new HookRegistry();
        $registry->declare('invoice.total.compute');
        $registry->declare('order.discount.apply');

        self::assertSame(['invoice.total.compute', 'order.discount.apply'], $registry->names());
        self::assertTrue($registry->has('invoice.total.compute'));
        self::assertFalse($registry->has('ghost.value.compute'));
    }
}
