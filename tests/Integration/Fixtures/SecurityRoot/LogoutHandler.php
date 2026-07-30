<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Security\Authentication\Authenticator;
use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LogoutHandler implements RequestHandlerInterface
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

        $this->authenticator->logout($session, ClientContext::fromRequest($request));

        return $this->json->response(200, ['status' => 'signed out']);
    }
}
