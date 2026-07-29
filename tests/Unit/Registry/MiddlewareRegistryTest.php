<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Http\Middleware\DispatchMiddleware;
use Liminal\Http\Middleware\ErrorHandlerMiddleware;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\MiddlewareRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewareRegistry::class)]
final class MiddlewareRegistryTest extends TestCase
{
    public function testLowerPriorityComesFirstRegardlessOfInsertionOrder(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->add(DispatchMiddleware::class, MiddlewareRegistry::DISPATCH);
        $registry->add(ErrorHandlerMiddleware::class, MiddlewareRegistry::ERROR_HANDLER);
        $registry->add(RouterMiddleware::class, MiddlewareRegistry::ROUTER);

        self::assertSame(
            [ErrorHandlerMiddleware::class, RouterMiddleware::class, DispatchMiddleware::class],
            $registry->all(),
        );
    }

    /**
     * Ties keep registration order (stable sort): whoever registers first at a
     * given priority stays outer — which is how the kernel's anchors win.
     */
    public function testEqualPrioritiesKeepRegistrationOrder(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->add(RouterMiddleware::class, 0);
        $registry->add(ErrorHandlerMiddleware::class, 0);

        self::assertSame([RouterMiddleware::class, ErrorHandlerMiddleware::class], $registry->all());
    }

    public function testTheOrderingSurvivesFreezing(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->add(DispatchMiddleware::class, 1000);
        $registry->add(ErrorHandlerMiddleware::class, -1000);
        $registry->freeze();

        self::assertSame([ErrorHandlerMiddleware::class, DispatchMiddleware::class], $registry->all());
    }

    public function testTheSameMiddlewareClassIsRefused(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->add(RouterMiddleware::class, 0);

        $this->expectException(DuplicateContributionException::class);

        $registry->add(RouterMiddleware::class, 500);
    }

    public function testContributingAfterFreezeIsRejected(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->freeze();

        $this->expectException(FrozenRegistryException::class);

        $registry->add(RouterMiddleware::class, 0);
    }
}
