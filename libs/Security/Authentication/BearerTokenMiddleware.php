<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Security\Contract\TokenProvider;
use Liminal\Lib\Security\Contract\UserProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns a valid Authorization: Bearer credential into a PreAuthentication
 * request attribute. Self-scoped on the header — requests without it pass
 * untouched and the whole HTML path stays byte-identical.
 *
 * Runs after the router on purpose: an unknown path 404s before any token
 * lookup, so path scanners never cost a query. A PRESENTED token that does
 * not validate is 401 always, public route or not — a credential is never
 * silently ignored (RFC 6750 posture: a typo'd token must read as exactly
 * that, not as a permission bug three screens later). One credential model
 * per request: when a Bearer header is present the session cookie's identity
 * is never consulted, because the CSRF exemption downstream keys on this
 * attribute and a cookie-borne identity must never ride it.
 *
 * The user is re-loaded through UserProvider::byId, so deactivation and
 * offboarding (an emptied accessible-company list) revoke API access the
 * same instant they revoke the browser's.
 */
final readonly class BearerTokenMiddleware implements MiddlewareInterface
{
    private const string SCHEME = 'Bearer ';

    public function __construct(
        private TokenProvider $tokens,
        private UserProvider $users,
    ) {}

    /**
     * @throws HttpException as invalidBearerToken() when a presented token does not validate
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');

        // A bare "Bearer" is a presented-but-empty credential (PSR-7 trims
        // the trailing space away), not somebody else's scheme.
        if ($authorization === rtrim(self::SCHEME)) {
            throw HttpException::invalidBearerToken();
        }

        if (!str_starts_with($authorization, self::SCHEME)) {
            return $handler->handle($request);
        }

        $raw = substr($authorization, strlen(self::SCHEME));

        if (trim($raw) === '') {
            throw HttpException::invalidBearerToken();
        }

        $userId = $this->tokens->authenticate($raw);

        if ($userId === null) {
            throw HttpException::invalidBearerToken();
        }

        $user = $this->users->byId($userId);

        if ($user === null || $user->accessibleCompanyIds() === []) {
            throw HttpException::invalidBearerToken();
        }

        return $handler->handle(
            $request->withAttribute(PreAuthentication::ATTRIBUTE, new PreAuthentication($user)),
        );
    }
}
