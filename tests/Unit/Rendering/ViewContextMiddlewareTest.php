<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Rendering;

use DateTimeImmutable;
use Liminal\Lib\Rendering\Http\ViewContextMiddleware;
use Liminal\Lib\Rendering\View\ViewContext;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(ViewContextMiddleware::class)]
#[CoversClass(ViewContext::class)]
final class ViewContextMiddlewareTest extends TestCase
{
    public function testTheRequestsSessionIsAssignedToTheHolder(): void
    {
        $holder = new ViewContext();
        $session = Session::fresh('a', new DateTimeImmutable());

        new ViewContextMiddleware($holder)->process(
            new Psr17Factory()->createServerRequest('GET', '/')
                ->withAttribute(SessionMiddleware::ATTRIBUTE, $session),
            $this->respondingHandler(),
        );

        self::assertSame($session, $holder->get());
    }

    /**
     * Worker-mode hygiene: a session from a previous request must never
     * survive into a request that carries none.
     */
    public function testARequestWithoutASessionClearsTheHolder(): void
    {
        $holder = new ViewContext();
        $holder->set(Session::fresh('stale', new DateTimeImmutable()));

        new ViewContextMiddleware($holder)->process(
            new Psr17Factory()->createServerRequest('GET', '/'),
            $this->respondingHandler(),
        );

        self::assertNull($holder->get());
    }

    private function respondingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr17Factory()->createResponse(200);
            }
        };
    }
}
