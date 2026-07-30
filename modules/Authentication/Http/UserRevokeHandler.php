<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Removes one grant.
 *
 * Self-revocation is deliberately allowed while self-deactivation is not: a
 * revocation is per company and any other administrator can restore it, while
 * deactivation is global and possibly nobody is left to undo it. Revoking
 * your own last grant simply makes your next request an orderly forced
 * logout — the fail-closed path that already existed.
 */
final readonly class UserRevokeHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private UserAdministration $users,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    /**
     * @throws HttpException as notFound() when no such user exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(UserListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        if ($this->users->userById($id) === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $companyId = is_numeric($body['company'] ?? null) ? (int) $body['company'] : 0;
        $roleId = is_numeric($body['role'] ?? null) ? (int) $body['role'] : 0;

        if ($this->users->revoke($id, $companyId, $roleId)) {
            $session->set('success', 'authentication.user.grant_removed');
        } else {
            $session->set('error', 'authentication.user.grant_missing');
        }

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.user', ['id' => $id]));
    }
}
