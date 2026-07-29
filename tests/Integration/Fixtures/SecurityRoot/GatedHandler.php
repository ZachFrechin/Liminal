<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Http\JsonResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers only when its module is enabled for the current company.
 */
final readonly class GatedHandler implements RequestHandlerInterface
{
    public function __construct(private JsonResponseFactory $json) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->response(200, ['module' => 'gated']);
    }
}
