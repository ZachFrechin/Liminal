<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One user, editable: display name, active flag, deletion — and the grants
 * that decide where they can work.
 */
final readonly class UserDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private UserAdministration $users,
        private CurrentUser $currentUser,
    ) {}

    /**
     * @throws HttpException as notFound() when no such user exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(UserListHandler::PERMISSION);

        $raw = $request->getAttribute('id');
        // The router matched {id:\d+}, so this is a numeric string; 0 (never
        // a real autoincrement id) covers the impossible fallthrough.
        $id = is_numeric($raw) ? (int) $raw : 0;
        $user = $this->users->userById($id);

        if ($user === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        return $this->html->respond(
            '@authentication/user.html.twig',
            [
                'account' => $user,
                'grants' => $this->users->grantsForUser($id),
                'companies' => $this->users->listCompanies(),
                'roles' => $this->users->listRoles(),
                // The template disables the self-destructive controls the
                // POST handlers refuse anyway: the guard is theirs, the
                // greyed-out button is honesty about it.
                'isSelf' => $this->currentUser->get()?->id() === $id,
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
