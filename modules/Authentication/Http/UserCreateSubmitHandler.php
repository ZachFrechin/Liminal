<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Liminal\Module\Authentication\Security\OneTimePassword;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Creates the user and reveals the generated password.
 *
 * Success deliberately RENDERS from the POST instead of redirecting — the one
 * sanctioned deviation from the flash-and-redirect rule. That rule exists to
 * carry state through a redirect via the session; this secret must never sit
 * in the session or any other store, so the only honest channel is the one
 * response body, sent with no-store and never logged. A refresh re-POSTs and
 * lands on the duplicate-email refusal — annoying, safe.
 *
 * Validation failures keep the ordinary shape: flash and 302 back to the form.
 */
final readonly class UserCreateSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private UserAdministration $users,
        private PasswordHasher $hasher,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(UserListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $email = trim($this->field($body, 'email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $session->set('error', 'authentication.user.invalid_email');

            return $this->backToForm();
        }

        $displayName = trim($this->field($body, 'display_name'))
            ?: explode('@', $email)[0];

        $password = OneTimePassword::generate();

        try {
            $id = $this->users->createUser($email, $this->hasher->hash($password), $displayName);
        } catch (UniqueConstraintViolationException) {
            $session->set('error', 'authentication.user.email_taken');

            return $this->backToForm();
        }

        return $this->html->respond(
            '@authentication/one_time_password.html.twig',
            [
                'titleKey' => 'authentication.user.created_title',
                'email' => $email,
                'userId' => $id,
                'password' => $password,
            ],
            200,
            // The only copy of the secret that will ever exist in the clear.
            ['Cache-Control' => 'no-store'],
        );
    }

    private function backToForm(): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.user_create'));
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
