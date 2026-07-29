<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Security\Csrf\CsrfTokenManager;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for a rendered form: hands out the session's CSRF token — whose
 * lazy generation is the anonymous session's first write.
 */
final readonly class TokenHandler implements RequestHandlerInterface
{
    public function __construct(
        private CsrfTokenManager $tokens,
        private JsonResponseFactory $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            return $this->json->response(500, ['error' => 'no session']);
        }

        return $this->json->response(200, ['token' => $this->tokens->token($session)]);
    }
}
