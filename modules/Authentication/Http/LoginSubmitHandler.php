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
 * Verifies the credentials and sends the browser onwards.
 *
 * Every failure RETURNS a response with a flash — it never throws. That is the
 * house rule with teeth: the session middleware persists nothing on the unwind
 * path, so a thrown failure would lose the very flash the next page needs to
 * explain itself. Redirecting rather than rendering also avoids the browser's
 * resubmit dialog, and leaves the CSRF token in place (only success retires it)
 * so the re-rendered form still works.
 */
final readonly class LoginSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private Authenticator $authenticator,
        private LoginRedirect $redirect,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $identifier = $this->field($body, 'identifier');
        $target = $this->redirect->resolve($this->field($body, 'redirect') ?: null);

        $result = $this->authenticator->attempt(
            $identifier,
            $this->field($body, 'password'),
            $session,
            ClientContext::fromRequest($request),
        );

        if ($result->wasThrottled()) {
            $session->set('error', 'authentication.login.throttled');

            // Retry-After is the honest signal even on a redirect: a client
            // that reads it learns to wait rather than to hammer.
            return $this->back($target)->withHeader('Retry-After', (string) $result->retryAfterSeconds);
        }

        if (!$result->isGranted()) {
            $session->set('error', 'authentication.login.refused');

            return $this->back($target);
        }

        $session->set('success', 'authentication.login.welcome');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $target ?? $this->urls->generate('authentication.account'));
    }

    private function back(?string $target): ResponseInterface
    {
        $location = $this->urls->generate('authentication.login');

        if ($target !== null) {
            // Carry the intended destination through the failure so a retry
            // still lands where the user was going.
            $location .= '?redirect=' . rawurlencode($target);
        }

        return $this->responses->createResponse(302)->withHeader('Location', $location);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function field(array $body, string $name): string
    {
        $value = $body[$name] ?? null;

        return is_string($value) ? $value : '';
    }
}
