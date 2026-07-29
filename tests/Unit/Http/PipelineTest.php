<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Http;

use Closure;
use Liminal\Http\Exception\EmptyPipelineException;
use Liminal\Http\Pipeline;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Pipeline::class)]
final class PipelineTest extends TestCase
{
    public function testMiddlewareRunsInOrderUntilOneResponds(): void
    {
        $trace = [];

        $pipeline = new Pipeline([
            $this->passing('outer', $trace),
            $this->passing('inner', $trace),
            $this->responding(204),
        ]);

        $response = $pipeline->handle($this->request());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['outer', 'inner'], $trace);
    }

    public function testAMiddlewareCanShortCircuit(): void
    {
        $trace = [];

        $pipeline = new Pipeline([
            $this->responding(403),
            $this->passing('never reached', $trace),
        ]);

        self::assertSame(403, $pipeline->handle($this->request())->getStatusCode());
        self::assertSame([], $trace);
    }

    public function testAnExhaustedPipelineIsAConstructionError(): void
    {
        $trace = [];

        $pipeline = new Pipeline([$this->passing('only', $trace)]);

        $this->expectException(EmptyPipelineException::class);

        $pipeline->handle($this->request());
    }

    /**
     * The same instance serves any number of requests: each handle() walks a
     * fresh cursor, no shared state survives a run.
     */
    public function testOneInstanceServesSuccessiveRequests(): void
    {
        $trace = [];

        $pipeline = new Pipeline([$this->passing('m', $trace), $this->responding(200)]);

        $pipeline->handle($this->request());
        $pipeline->handle($this->request());

        self::assertSame(['m', 'm'], $trace);
    }

    private function request(): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', '/');
    }

    /**
     * @param list<string> $trace
     */
    private function passing(string $label, array &$trace): MiddlewareInterface
    {
        $record = static function () use ($label, &$trace): void {
            $trace[] = $label;
        };

        return new class ($record) implements MiddlewareInterface {
            public function __construct(private readonly Closure $record) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                ($this->record)();

                return $handler->handle($request);
            }
        };
    }

    private function responding(int $status): MiddlewareInterface
    {
        return new class ($status) implements MiddlewareInterface {
            public function __construct(private readonly int $status) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Psr17Factory()->createResponse($this->status);
            }
        };
    }
}
