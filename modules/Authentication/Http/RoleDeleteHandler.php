<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
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
 * Deletes a role — a MASS REVOCATION, since every holder's grant in every
 * company cascades away with it, the actor's own included.
 *
 * The admin code is refused here with a flash for the human, and refused
 * again in the service with an exception for whatever UI forgets: the screen
 * is politeness, the service is the guard.
 */
final readonly class RoleDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private UserAdministration $users,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
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

        $role = $this->users->roleById($id);

        if ($role === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if ($role['code'] === UserAdministration::ADMIN_ROLE_CODE) {
            $session->set('error', 'authentication.role.admin_protected');

            return $this->responses->createResponse(302)
                ->withHeader('Location', $this->urls->generate('authentication.role', ['id' => $id]));
        }

        $this->users->deleteRole($id);
        $this->triggers->fire('ROLE_DELETED', ['role_id' => $id, 'code' => $role['code']]);
        $session->set('success', 'authentication.role.deleted');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.roles'));
    }
}
