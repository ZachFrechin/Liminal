<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The form that mints a role: code (immutable afterwards, like a company
 * code), label, and the declared permissions as checkboxes.
 */
final readonly class RoleCreatePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private RoleFormSupport $form,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(RoleListHandler::PERMISSION);

        return $this->html->respond(
            '@authentication/role_create.html.twig',
            ['groups' => $this->form->grouped()],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
