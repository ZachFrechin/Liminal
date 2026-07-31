<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\RouteMatch;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Exception\AuthenticationException;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deny-by-default: every route requires an authenticated user unless it was
 * declared public. Runs after the router (it reads the matched Route's flag)
 * and resolves the session's user id through the UserProvider.
 *
 * A user id whose user no longer exists — or whose accessible-company list
 * emptied — is logged out on the spot, never a 500: deletion and offboarding
 * are data states. The CurrentUser holder is assigned UNCONDITIONALLY, set or
 * cleared, so no identity ever leaks into the next request of a worker-mode
 * runtime.
 *
 * A PreAuthentication attribute (a bearer credential validated upstream)
 * takes precedence over the session: the identity travels in the request —
 * per-request, immutable — and the session is not consulted at all. The
 * holder is still assigned on every request; only the source changes.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserProvider $users,
        private CurrentUser $currentUser,
    ) {}

    /**
     * @throws AuthenticationException when session or route middleware did not run first
     * @throws HttpException           as unauthorized() on a protected route without a user
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationException::sessionMissing(self::class);
        }

        $match = $request->getAttribute(RouterMiddleware::ATTRIBUTE);

        if (!$match instanceof RouteMatch || $match->route === null) {
            throw AuthenticationException::routeMissing(self::class);
        }

        $pre = $request->getAttribute(PreAuthentication::ATTRIBUTE);

        if ($pre instanceof PreAuthentication) {
            $this->currentUser->set($pre->user);

            return $handler->handle($request);
        }

        $user = null;
        $userId = $session->userId();

        if ($userId !== null) {
            $user = $this->users->byId($userId);

            if ($user === null || $user->accessibleCompanyIds() === []) {
                $session->setUserId(null);
                $session->setCompanyId(null);
                $user = null;
            }
        }

        $this->currentUser->set($user);

        if ($user === null && !$match->route->public) {
            throw HttpException::unauthorized();
        }

        return $handler->handle($request);
    }
}
