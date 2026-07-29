<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Csrf;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Security\Exception\AuthenticationException;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses unsafe-method requests without a valid synchronizer token. Runs
 * post-routing but needs only the session and the method — which is exactly
 * why the login POST on a public route is covered too: the anonymous
 * session's token protects the login form itself.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const string FIELD = '_token';

    public const string HEADER = 'X-CSRF-Token';

    /** RFC 7231 safe methods are exempt. */
    private const array UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private CsrfTokenManager $tokens) {}

    /**
     * @throws AuthenticationException when the session middleware did not run first
     * @throws HttpException           as csrfTokenMismatch() when the token is absent or wrong
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationException::sessionMissing(self::class);
        }

        if (!$this->tokens->validate($session, $this->presentedToken($request))) {
            throw HttpException::csrfTokenMismatch();
        }

        return $handler->handle($request);
    }

    private function presentedToken(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && is_string($body[self::FIELD] ?? null)) {
            return $body[self::FIELD];
        }

        return $request->getHeaderLine(self::HEADER);
    }
}
