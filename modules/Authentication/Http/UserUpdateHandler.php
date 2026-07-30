<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
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
 * Renames and (de)activates a user.
 *
 * Self-deactivation is refused: it is global (every company at once) and the
 * actor would lock themselves out with no guarantee anyone else can undo it.
 * Deactivating someone ELSE ends their live session at their next request —
 * is_active is filtered on session hydration, not just at login.
 */
final readonly class UserUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private UserAdministration $users,
        private CurrentUser $currentUser,
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
        // The router matched {id:\d+}, so this is a numeric string; 0 (never
        // a real autoincrement id) covers the impossible fallthrough.
        $id = is_numeric($raw) ? (int) $raw : 0;

        if ($this->users->userById($id) === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $displayName = trim(is_string($body['display_name'] ?? null) ? $body['display_name'] : '');
        $active = ($body['active'] ?? null) === '1';

        if ($displayName === '') {
            $session->set('error', 'authentication.user.name_required');

            return $this->backToDetail($id);
        }

        if (!$active && $this->currentUser->get()?->id() === $id) {
            $session->set('error', 'authentication.user.self_deactivation');

            return $this->backToDetail($id);
        }

        $this->users->updateDisplayName($id, $displayName);
        $this->users->setActive($id, $active);
        $session->set('success', 'authentication.user.updated');

        return $this->backToDetail($id);
    }

    private function backToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.user', ['id' => $id]));
    }
}
