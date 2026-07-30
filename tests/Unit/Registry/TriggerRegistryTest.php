<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\UndeclaredListenerTargetException;
use Liminal\Registry\TriggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TriggerRegistry::class)]
final class TriggerRegistryTest extends TestCase
{
    /**
     * The pinned merge rule: lower priority runs earlier; at equal priority,
     * name-specific listeners precede catch-alls, each group keeping its
     * registration order.
     */
    public function testTheMergeRuleHoldsAcrossSpecificsAndCatchAlls(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('USER_CREATED');
        $registry->listenToAll('CatchAllAtTie');
        $registry->listen('USER_CREATED', 'SpecificAtTie');
        $registry->listenToAll('AnchorAudit', priority: -1000);
        $registry->listen('USER_CREATED', 'LateSpecific', priority: 500);

        self::assertSame(
            ['AnchorAudit', 'SpecificAtTie', 'CatchAllAtTie', 'LateSpecific'],
            $registry->listenersFor('USER_CREATED'),
        );
    }

    public function testACatchAllHearsATriggerItNeverNamed(): void
    {
        $registry = new TriggerRegistry();
        $registry->listenToAll('Audit');
        $registry->declare('FUTURE_MODULE_DID_SOMETHING');

        $registry->freeze();

        self::assertSame(['Audit'], $registry->listenersFor('FUTURE_MODULE_DID_SOMETHING'));
    }

    public function testFreezeRefusesASubscriptionNobodyDeclares(): void
    {
        $registry = new TriggerRegistry();
        $registry->listen('GHOST_EVENT', 'Listener');

        $this->expectException(UndeclaredListenerTargetException::class);

        $registry->freeze();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'lowercase' => ['user_created'];
        yield 'dotted' => ['user.created'];
        yield 'mixed case' => ['User_Created'];
        yield 'leading digit' => ['1USER'];
        yield 'leading underscore' => ['_USER'];
        yield 'empty' => [''];
        yield 'over the storage width' => [str_repeat('A', 65)];
    }

    #[DataProvider('invalidNames')]
    public function testTheGrammarRefusesWhatCannotBeStoredOrRead(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TriggerRegistry()->declare($name);
    }

    public function testDuplicateDeclarationsAndSubscriptionsAreRefused(): void
    {
        $registry = new TriggerRegistry();
        $registry->declare('USER_CREATED');
        $registry->listen('USER_CREATED', 'Listener');
        $registry->listenToAll('Audit');

        try {
            $registry->declare('USER_CREATED');
            self::fail('A duplicate declaration must be refused.');
        } catch (DuplicateContributionException) {
        }

        try {
            $registry->listen('USER_CREATED', 'Listener');
            self::fail('A duplicate subscription must be refused.');
        } catch (DuplicateContributionException) {
        }

        $this->expectException(DuplicateContributionException::class);

        $registry->listenToAll('Audit');
    }
}
