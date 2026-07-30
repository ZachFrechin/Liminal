<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Http;

use Liminal\Lib\Rendering\View\ViewContext;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Primes the ViewContext for the request — at −850, immediately inside the
 * session middleware, so EVERY HttpException thrower downstream (router 0,
 * auth 100, CSRF 200, gate 400, dispatch) unwinds past an already-primed
 * holder and the HTML error page at −950 renders this request's state, never
 * a previous request's.
 *
 * Unconditional set-or-clear: no session may survive into the next request of
 * a worker-mode runtime (the CurrentUser rule). Deliberately no try/finally —
 * a finally would wipe the holder BEFORE −950 renders the error page.
 */
final readonly class ViewContextMiddleware implements MiddlewareInterface
{
    public function __construct(private ViewContext $viewContext) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        $this->viewContext->set($session instanceof Session ? $session : null);

        return $handler->handle($request);
    }
}
