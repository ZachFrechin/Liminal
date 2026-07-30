<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authentication\CurrentUser;
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
 * Deletes a user. The schema owns the fallout — sessions and grants cascade,
 * the audit trail keeps its rows minus the user id — and self-deletion is
 * refused for the same reason self-deactivation is: global, immediate, and
 * possibly nobody left to undo it.
 */
final readonly class UserDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private UserAdministration $users,
        private CurrentUser $currentUser,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
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
        // The router matched {id:\d+}, so this is a numeric string; 0 (never
        // a real autoincrement id) covers the impossible fallthrough.
        $id = is_numeric($raw) ? (int) $raw : 0;

        $user = $this->users->userById($id);

        if ($user === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if ($this->currentUser->get()?->id() === $id) {
            $session->set('error', 'authentication.user.self_deletion');

            return $this->responses->createResponse(302)
                ->withHeader('Location', $this->urls->generate('authentication.user', ['id' => $id]));
        }

        $this->users->deleteUser($id);
        // The row is gone; the hoisted copy is what the audit remembers.
        $this->triggers->fire('USER_DELETED', ['user_id' => $id, 'email' => $user['email']]);
        $session->set('success', 'authentication.user.deleted');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.users'));
    }
}
