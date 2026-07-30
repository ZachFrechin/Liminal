<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One role: the code on display (never editable — renaming admin must stay
 * structurally impossible), the label, and every declared permission as a
 * checkbox. Stored codes the registry no longer declares render as "unknown"
 * and vanish at the next save; they are never fed to Gate::allows, which
 * throws on undeclared codes by design.
 */
final readonly class RoleDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private UserAdministration $users,
        private RoleFormSupport $form,
    ) {}

    /**
     * @throws HttpException as notFound() when no such role exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(RoleListHandler::PERMISSION);

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $role = $this->users->roleById($id);

        if ($role === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $held = $this->users->permissionsOfRole($id);

        return $this->html->respond(
            '@authentication/role.html.twig',
            [
                'role' => $role,
                'held' => $held,
                'groups' => $this->form->grouped(),
                'unknown' => $this->form->unknownAmong($held),
                'isAdmin' => $role['code'] === UserAdministration::ADMIN_ROLE_CODE,
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
