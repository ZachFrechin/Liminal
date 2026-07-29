<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the session on the way in and persists it on the way out.
 *
 * There is deliberately no try/finally. The error handler is the OUTERMOST
 * middleware, so an exception unwinds through this frame before becoming a
 * response: a failed request persists nothing. That is the posture we want —
 * a 404 from a crawler, a 401 spray, a CSRF refusal all write zero rows.
 *
 * The corollary matters for everything built on top: a failed login must be
 * signalled by returning a response, never by throwing, or the attempt's
 * session state (flash messages, future throttling counters) is lost.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'liminal.session';

    public function __construct(private SessionManager $sessions) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->sessions->start($request);

        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $session));

        return $this->sessions->persist($session, $response);
    }
}
