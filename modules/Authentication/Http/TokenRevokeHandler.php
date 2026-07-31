<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Self-service revocation: only the owner's own tokens — the ownership guard
 * lives in TokenAdministration::revoke, and someone else's id answers the
 * same flash as an unknown one, revealing nothing.
 */
final readonly class TokenRevokeHandler implements RequestHandlerInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private TokenAdministration $tokens,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser->get() ?? throw AuthenticationModuleException::sessionMissing();

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        if ($this->tokens->revoke($id, ownedBy: $user->id()) === null) {
            $session->set('error', 'authentication.token.unknown');

            return $this->backToAccount();
        }

        $this->triggers->fire('TOKEN_REVOKED', ['token_id' => $id, 'user_id' => $user->id()]);
        $session->set('success', 'authentication.token.revoked');

        return $this->backToAccount();
    }

    private function backToAccount(): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.account'));
    }
}
