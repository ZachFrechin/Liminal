<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit;

use Liminal\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The hook primitive through a full kernel and a real HTTP round trip: a
 * fixture module declares a hook, two prioritized listeners transform the
 * value, and the response carries the composed result — no database anywhere
 * near it.
 */
#[CoversNothing]
final class HookEndToEndTest extends TestCase
{
    private const ROOT = __DIR__ . '/Fixtures/Kernel/hooks';

    public function testTheValueTravelsThroughThePrioritizedChainOverHttp(): void
    {
        $response = new Kernel(self::ROOT)->handle(
            new Psr17Factory()->createServerRequest('GET', '/compute'),
        );

        self::assertSame(200, $response->getStatusCode());
        // (5 + 10) * 2 — priority 100 before 200, whatever the registration order.
        self::assertSame('{"value":30}', (string) $response->getBody());
    }
}
