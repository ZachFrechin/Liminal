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
            throw AuthenticationException::sessionMissing();
        }

        $match = $request->getAttribute(RouterMiddleware::ATTRIBUTE);

        if (!$match instanceof RouteMatch || $match->route === null) {
            throw AuthenticationException::routeMissing();
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
