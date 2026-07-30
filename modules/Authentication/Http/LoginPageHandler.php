<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The sign-in form. Public, and gated by nothing: a public route is
 * pre-authentication and therefore pre-company, so the module gate leaves it
 * alone — which is what makes it reachable before anyone can enable anything.
 */
final readonly class LoginPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private LoginRedirect $redirect,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $target = $request->getQueryParams()['redirect'] ?? null;

        return $this->html->respond('@authentication/login.html.twig', [
            // Validated here so the form never carries a target the POST would
            // refuse anyway, and never echoes an unvalidated value.
            'redirect' => $this->redirect->resolve(is_string($target) ? $target : null),
        ]);
    }
}
