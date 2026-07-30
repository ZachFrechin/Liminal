<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The form that mints a user: email and display name only — the password is
 * generated server-side and revealed once by the submit handler.
 */
final readonly class UserCreatePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(UserListHandler::PERMISSION);

        return $this->html->respond(
            '@authentication/user_create.html.twig',
            [],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
