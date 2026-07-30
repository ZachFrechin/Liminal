<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Security\Authentication\Authenticator;
use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Signs the user out.
 *
 * Declared PUBLIC on purpose: logging out must never depend on being logged in.
 * A half-expired session, a company that vanished, or a module disabled for your
 * company must all still be able to end the session. CSRF protection is method
 * based rather than route based, so this POST is still token-protected — which
 * is what stops a forced-logout cross-site request. An anonymous POST is an
 * idempotent no-op.
 */
final readonly class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(
        private Authenticator $authenticator,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $this->authenticator->logout($session, ClientContext::fromRequest($request));
        $session->set('success', 'authentication.logout.done');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.login'));
    }
}
