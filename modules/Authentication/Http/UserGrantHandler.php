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
 * Adds one grant: this user, that company, that role. The selects on the form
 * only offer real rows, so an unknown company or role here means a tampered
 * form — refused with the same polite flash as any validation failure,
 * because a tampering probe deserves no richer oracle than a typo.
 */
final readonly class UserGrantHandler implements RequestHandlerInterface
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

        if (!$this->users->companyExists($companyId) || $this->users->roleById($roleId) === null) {
            $session->set('error', 'authentication.user.grant_invalid');

            return $this->backToDetail($id);
        }

        $session->set(
            'success',
            $this->users->grant($id, $companyId, $roleId)
                ? 'authentication.user.grant_added'
                : 'authentication.user.grant_already',
        );

        return $this->backToDetail($id);
    }

    private function backToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.user', ['id' => $id]));
    }
}
