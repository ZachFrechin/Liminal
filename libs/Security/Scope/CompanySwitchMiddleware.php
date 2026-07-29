<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Scope;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Exception\AuthenticationException;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Points the company scope at the authenticated user's company for the
 * duration of the request — the middleware that finally makes the multi-company
 * promise of the database lib hold end to end.
 *
 * The stored preference is treated as untrusted: a company the user cannot
 * reach falls back to their first accessible one. The switch happens
 * UNCONDITIONALLY, including the anonymous bootstrap case: that is what stops
 * a worker-mode runtime from serving one request under the previous request's
 * scope. Guarding on the current id alone would even be wrong — the accessible
 * list feeds the SQL filter's IN (...) and changes when the id does not.
 */
final readonly class CompanySwitchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private CompanyContext $context,
        private int $bootstrapCompanyId,
    ) {}

    /**
     * @throws AuthenticationException when the session middleware did not run first
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->currentUser->get();

        if ($user === null) {
            $this->context->switchTo($this->bootstrapCompanyId);

            return $handler->handle($request);
        }

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationException::sessionMissing(self::class);
        }

        // Non-empty by contract: AuthenticationMiddleware logs out any user
        // whose accessible list emptied, so this request never sees one.
        $accessible = $user->accessibleCompanyIds();
        $wanted = $session->companyId();

        if ($wanted === null || !in_array($wanted, $accessible, true)) {
            $wanted = $accessible[0] ?? $this->bootstrapCompanyId;
        }

        // setCompanyId() only dirties the session when the value moved.
        $session->setCompanyId($wanted);
        $this->context->switchTo($wanted, ...$accessible);

        return $handler->handle($request);
    }
}
