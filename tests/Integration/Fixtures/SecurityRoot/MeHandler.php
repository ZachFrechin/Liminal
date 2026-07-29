<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protected echo endpoint: who am I, and which company scope am I under.
 */
final readonly class MeHandler implements RequestHandlerInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private CompanyContext $context,
        private JsonResponseFactory $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser->get();

        return $this->json->response(200, [
            'user' => $user?->id(),
            'company' => $this->context->currentId(),
        ]);
    }
}
