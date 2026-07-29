<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Database;

use InvalidArgumentException;
use Liminal\Lib\Database\Scope\CompanyContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyContext::class)]
final class CompanyContextTest extends TestCase
{
    public function testTheCurrentCompanyIsAlwaysReachable(): void
    {
        self::assertSame([7], (new CompanyContext(7))->accessibleIds());
    }

    public function testAccessibleIdsAreDeduplicatedAndSorted(): void
    {
        self::assertSame([1, 3, 5], (new CompanyContext(3, 5, 1, 3))->accessibleIds());
    }

    public function testCanAccessRejectsUnknownAndNullCompanies(): void
    {
        $context = new CompanyContext(1, 2);

        self::assertTrue($context->canAccess(2));
        self::assertFalse($context->canAccess(9));
        self::assertFalse($context->canAccess(null));
    }

    /**
     * switchTo() has to notify, not just assign: the EntityManager must clear its
     * identity map or entities hydrated under the old scope stay reachable.
     */
    public function testSwitchingNotifiesListeners(): void
    {
        $context = new CompanyContext(1);
        $seen = [];

        $context->onSwitch(static function (CompanyContext $c) use (&$seen): void {
            $seen[] = $c->currentId();
        });

        $context->switchTo(2);
        $context->switchTo(3);

        self::assertSame([2, 3], $seen);
        self::assertSame(3, $context->currentId());
    }

    public function testANonPositiveCompanyIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompanyContext(0);
    }
}
