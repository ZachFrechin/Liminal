<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Api;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Database\Pagination\Page;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiResponder::class)]
final class ApiResponderTest extends TestCase
{
    public function testAnItemTravelsInTheDataEnvelope(): void
    {
        $response = $this->responder()->item(['id' => 7, 'name' => 'Wayne']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"data":{"id":7,"name":"Wayne"}}', (string) $response->getBody());
    }

    public function testACollectionCarriesExactlyWhatThePageKnows(): void
    {
        $page = new Page([new ThingStub(1), new ThingStub(2)], 27, 2, 3, 25);

        $response = $this->responder()->collection(
            $page,
            static fn(ThingStub $thing): array => ['id' => $thing->id],
        );

        self::assertSame(
            '{"data":[{"id":1},{"id":2}],"meta":{"page":2,"pages":3,"total":27,"perPage":25}}',
            (string) $response->getBody(),
        );
    }

    private function responder(): ApiResponder
    {
        return new ApiResponder(new JsonResponseFactory(new Psr17Factory()));
    }
}

final readonly class ThingStub
{
    public function __construct(public int $id) {}
}
