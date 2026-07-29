<?php

declare(strict_types=1);

namespace Liminal\Http;

use Liminal\Http\Exception\EmptyPipelineException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 request handler that walks an immutable list of middleware.
 *
 * Each call to handle() advances one step, so the same Pipeline instance can serve
 * concurrent requests without sharing cursor state.
 */
final readonly class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /**
     * @param iterable<MiddlewareInterface> $middleware
     */
    public function __construct(iterable $middleware, private int $index = 0)
    {
        $this->middleware = is_array($middleware) ? array_values($middleware) : iterator_to_array($middleware, false);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->middleware[$this->index] ?? throw new EmptyPipelineException(
            'The middleware pipeline was exhausted without producing a response.',
        );

        return $middleware->process($request, new self($this->middleware, $this->index + 1));
    }
}
