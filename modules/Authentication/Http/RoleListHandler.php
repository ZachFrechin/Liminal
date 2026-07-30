<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The roles of the instance with their permission and grant counts.
 *
 * Gated by its own permission, distinct from user.manage: editing what a role
 * MEANS is a privilege-escalation surface with a different blast radius than
 * deciding who holds it.
 */
final readonly class RoleListHandler implements RequestHandlerInterface
{
    public const string PERMISSION = 'authentication.role.manage';

    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private UserAdministration $users,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(self::PERMISSION);

        return $this->html->respond(
            '@authentication/roles.html.twig',
            ['roles' => $this->users->listRoles()],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
