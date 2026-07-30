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
 * Replaces a role's label and permission set. Takes effect on every holder's
 * NEXT request: the resolver memoises per (user, company) within a request
 * and rebuilds from the database on the next one.
 *
 * Editing the admin role's permissions IS allowed — a revocation there may be
 * deliberate, which is the drift-report's whole philosophy. Only the code is
 * beyond reach, because commands and migrations anchor on it.
 */
final readonly class RoleUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private UserAdministration $users,
        private RoleFormSupport $form,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    /**
     * @throws HttpException as notFound() when no such role exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(RoleListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        if ($this->users->roleById($id) === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $label = trim(is_string($body['label'] ?? null) ? $body['label'] : '');

        if ($label === '') {
            $session->set('error', 'authentication.role.invalid');
        } else {
            $this->users->updateRole($id, $label, $this->form->declaredOnly($body['permissions'] ?? null));
            $session->set('success', 'authentication.role.updated');
        }

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.role', ['id' => $id]));
    }
}
