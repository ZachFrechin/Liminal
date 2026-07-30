<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionManager;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Liminal\Module\Authentication\Security\OneTimePassword;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Replaces a user's password with a fresh generated one, shown once — the
 * same no-PRG deviation as creation, for the same reason: the secret's only
 * clear-text copy is this response body.
 *
 * A reset is a credential change, so every OTHER session of that user ends
 * with it: a session an attacker may already hold must not outlive the
 * password it was minted under. The actor's own session survives a
 * self-reset — they just proved who they are.
 */
final readonly class UserPasswordResetHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private UserAdministration $users,
        private PasswordHasher $hasher,
        private SessionManager $sessions,
        private Triggers $triggers,
    ) {}

    /**
     * @throws HttpException as notFound() when no such user exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(UserListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $user = $this->users->userById($id);

        if ($user === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $password = OneTimePassword::generate();

        $this->users->replacePasswordHash($id, $this->hasher->hash($password));
        $this->sessions->endAllFor($id, $session->id());
        // After endAllFor: the event means "reset COMPLETED". The secret is in
        // scope right here and must never enter a payload.
        $this->triggers->fire('USER_PASSWORD_RESET', ['user_id' => $id, 'email' => $user['email']]);

        return $this->html->respond(
            '@authentication/one_time_password.html.twig',
            [
                'titleKey' => 'authentication.user.password_reset_title',
                'email' => $user['email'],
                'userId' => $id,
                'password' => $password,
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
