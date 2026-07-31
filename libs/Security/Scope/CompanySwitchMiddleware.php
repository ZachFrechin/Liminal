<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Scope;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authentication\PreAuthentication;
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
 *
 * A pre-authenticated (bearer) request scopes from the REQUEST instead of the
 * session: the X-Liminal-Company header if present — refused outright when it
 * names a company outside the user's reach, because an explicitly requested
 * company must never be silently replaced (the session fallback exists only
 * because stored residue is untrusted; a header is a live assertion) — or the
 * first accessible company, the HTML default. The session is neither read nor
 * written on that branch: writing the preference would dirty a fresh
 * cookieless session and cost one core_session INSERT plus a Set-Cookie per
 * stateless call.
 */
final readonly class CompanySwitchMiddleware implements MiddlewareInterface
{
    public const string COMPANY_HEADER = 'X-Liminal-Company';

    public function __construct(
        private CurrentUser $currentUser,
        private CompanyContext $context,
        private int $bootstrapCompanyId,
    ) {}

    /**
     * @throws AuthenticationException when the session middleware did not run first
     * @throws HttpException           as forbidden() when the company header names a company out of reach
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->currentUser->get();

        if ($user === null) {
            $this->context->switchTo($this->bootstrapCompanyId);

            return $handler->handle($request);
        }

        if ($request->getAttribute(PreAuthentication::ATTRIBUTE) instanceof PreAuthentication) {
            $this->switchStateless($request, $user->accessibleCompanyIds());

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

    /**
     * @param list<int> $accessible never empty — the bearer middleware refuses
     *                              a user whose accessible list emptied
     *
     * @throws HttpException
     */
    private function switchStateless(ServerRequestInterface $request, array $accessible): void
    {
        $header = $request->getHeaderLine(self::COMPANY_HEADER);
        $wanted = $accessible[0] ?? $this->bootstrapCompanyId;

        if ($header !== '') {
            $requested = is_numeric($header) ? (int) $header : 0;

            if (!in_array($requested, $accessible, true)) {
                throw HttpException::forbidden();
            }

            $wanted = $requested;
        }

        $this->context->switchTo($wanted, ...$accessible);
    }
}
