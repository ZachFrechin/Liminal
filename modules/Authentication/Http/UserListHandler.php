<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The users of the current company, read-only.
 *
 * It exists in this phase so RBAC is not shipped untested: it is the only caller
 * of RequestGate::authorize(), and the only thing that makes "grant the role,
 * and the menu entry and the page appear" assertable. Phase 5b turns it into a
 * real CRUD.
 */
final readonly class UserListHandler implements RequestHandlerInterface
{
    public const string PERMISSION = 'authentication.user.manage';

    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private UserAdministration $users,
        private CompanyContext $context,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(self::PERMISSION);

        return $this->html->respond(
            '@authentication/users.html.twig',
            ['users' => $this->users->listForCompany($this->context->currentId())],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
