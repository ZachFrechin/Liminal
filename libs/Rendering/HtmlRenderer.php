<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Error\Error;

/**
 * The one place an HTML response is assembled — the JsonResponseFactory's
 * sibling, except that for HTML the payload IS template plus context, so
 * rendering and responding live together and a page handler needs exactly
 * one dependency.
 */
final readonly class HtmlRenderer
{
    public function __construct(
        private Environment $twig,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @throws Error when the template is missing, invalid, or a strict variable is absent
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    /**
     * @param array<string, mixed>  $context
     * @param array<string, string> $headers
     *
     * @throws Error when the template is missing, invalid, or a strict variable is absent
     */
    public function respond(
        string $template,
        array $context = [],
        int $status = 200,
        array $headers = [],
    ): ResponseInterface {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write($this->render($template, $context));

        return $response;
    }
}
