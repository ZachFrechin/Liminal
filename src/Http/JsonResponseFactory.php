<?php

declare(strict_types=1);

namespace Liminal\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The one place a JSON response is assembled — status, headers, charset and
 * encoding flags — so the error handler and every controller emit identical
 * envelopes.
 */
final readonly class JsonResponseFactory
{
    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function response(int $status, array $payload, array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $response;
    }
}
