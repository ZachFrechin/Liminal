<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Self-service token minting from the account page. Success RENDERS from the
 * POST — the sanctioned one-time-secret deviation: this response body is the
 * only copy of the raw token that will ever exist, sent with no-store, never
 * through the session or any other store. A refresh re-POSTs and mints
 * another token — annoying, safe, and visible in the list.
 *
 * No permission beyond authentication itself: a token authenticates AS the
 * user who minted it, so it grants nothing its owner does not already hold.
 */
final readonly class TokenCreateSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private CurrentUser $currentUser,
        private TokenAdministration $tokens,
        private Triggers $triggers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser->get() ?? throw AuthenticationModuleException::sessionMissing();

        $body = $request->getParsedBody();
        $rawLabel = is_array($body) && is_string($body['label'] ?? null) ? trim($body['label']) : '';
        $label = $rawLabel !== '' ? mb_substr($rawLabel, 0, 100) : 'api';

        $minted = $this->tokens->mint($user->id(), $label);

        // Post-commit, and without the secret.
        $this->triggers->fire('TOKEN_CREATED', [
            'token_id' => $minted->id,
            'user_id' => $user->id(),
            'label' => $label,
        ]);

        return $this->html->respond(
            '@authentication/one_time_token.html.twig',
            [
                'label' => $label,
                'token' => $minted->raw,
            ],
            200,
            // The only copy of the secret that will ever exist in the clear.
            ['Cache-Control' => 'no-store'],
        );
    }
}
