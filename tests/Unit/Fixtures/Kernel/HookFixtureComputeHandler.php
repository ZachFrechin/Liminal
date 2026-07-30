<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Hook\Hooks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The dispatch site: owns the base value, runs the filter, answers with what
 * came back — and, being the declarer, owns validating the returned shape.
 */
final readonly class HookFixtureComputeHandler implements RequestHandlerInterface
{
    public function __construct(
        private Hooks $hooks,
        private JsonResponseFactory $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $value = $this->hooks->filter('hook_fixture.value.compute', 5, ['origin' => 'fixture']);

        return $this->json->response(200, ['value' => is_int($value) ? $value : null]);
    }
}
