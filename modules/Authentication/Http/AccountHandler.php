<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where a successful login lands: who you are. The company switcher and
 * sign-out that used to live here moved into the shell, visible on every
 * page — this handler keeps the identity and will grow the profile.
 */
final readonly class AccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private CurrentUser $currentUser,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->respond(
            '@authentication/account.html.twig',
            [
                // Non-null here: the route is protected, so the authentication
                // middleware already refused anyone anonymous.
                'user' => $this->currentUser->get(),
            ],
            200,
            // An authenticated page on a shared machine must not sit in the
            // back-button cache after sign-out.
            ['Cache-Control' => 'no-store'],
        );
    }
}
