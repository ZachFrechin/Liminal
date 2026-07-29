<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Security\Authentication\Authenticator;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The fixture login endpoint. Failure RESPONDS (400) instead of throwing —
 * the session-persistence rule: an exception would unwind past the session
 * middleware and lose the attempt's state.
 */
final readonly class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private Authenticator $authenticator,
        private JsonResponseFactory $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            return $this->json->response(500, ['error' => 'no session']);
        }

        $body = $request->getParsedBody();
        $identifier = is_array($body) && is_string($body['identifier'] ?? null) ? $body['identifier'] : '';
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';

        $user = $this->authenticator->attempt($identifier, $password, $session);

        if ($user === null) {
            return $this->json->response(400, ['error' => 'invalid credentials']);
        }

        return $this->json->response(200, ['user' => $user->id()]);
    }
}
