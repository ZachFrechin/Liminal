<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where a successful login lands: who you are — and the API tokens that
 * authenticate as you. The company switcher and sign-out that used to live
 * here moved into the shell, visible on every page.
 */
final readonly class AccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private CurrentUser $currentUser,
        private TokenAdministration $tokens,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Non-null here: the route is protected, so the authentication
        // middleware already refused anyone anonymous.
        $user = $this->currentUser->get();

        return $this->html->respond(
            '@authentication/account.html.twig',
            [
                'user' => $user,
                'tokens' => $user !== null ? $this->tokens->listFor($user->id()) : [],
            ],
            200,
            // An authenticated page on a shared machine must not sit in the
            // back-button cache after sign-out.
            ['Cache-Control' => 'no-store'],
        );
    }
}
