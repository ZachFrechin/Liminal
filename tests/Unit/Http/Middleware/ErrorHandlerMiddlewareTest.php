<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Http\Middleware;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\JsonResponseFactory;
use Liminal\Http\Middleware\ErrorHandlerMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Throwable;

#[CoversClass(ErrorHandlerMiddleware::class)]
#[CoversClass(JsonResponseFactory::class)]
final class ErrorHandlerMiddlewareTest extends TestCase
{
    public function testAnHttpExceptionRendersItsStatusMessageAndHeaders(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->json(), new NullLogger());

        $response = $middleware->process(
            $this->request(),
            $this->throwing(HttpException::methodNotAllowed(['GET', 'HEAD'])),
        );

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Method not allowed.', (string) $response->getBody());
    }

    public function testAnUnexpectedThrowableIsLoggedAndMasked(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };

        $middleware = new ErrorHandlerMiddleware($this->json(), $logger, debug: false);

        $response = $middleware->process($this->request(), $this->throwing(new RuntimeException('secret detail')));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret detail', (string) $response->getBody());
        self::assertStringContainsString('Internal Server Error', (string) $response->getBody());
        self::assertCount(1, $logger->records);
    }

    public function testDebugModeExposesTheRealMessage(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->json(), new NullLogger(), debug: true);

        $response = $middleware->process($this->request(), $this->throwing(new RuntimeException('the real cause')));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('the real cause', (string) $response->getBody());
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $expected = new Psr17Factory()->createResponse(201);

        $handler = new class ($expected) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $middleware = new ErrorHandlerMiddleware($this->json(), new NullLogger());

        self::assertSame($expected, $middleware->process($this->request(), $handler));
    }

    private function json(): JsonResponseFactory
    {
        return new JsonResponseFactory(new Psr17Factory());
    }

    private function request(): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', '/thing');
    }

    private function throwing(Throwable $throwable): RequestHandlerInterface
    {
        return new class ($throwable) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $throwable) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->throwable;
            }
        };
    }
}
