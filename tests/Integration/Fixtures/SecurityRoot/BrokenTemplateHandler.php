<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Rendering\HtmlRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders a template referencing an undefined variable: strict_variables
 * turns that into a Twig error, which must reach the outer JSON handler
 * rather than being dressed up as an HTML page.
 */
final readonly class BrokenTemplateHandler implements RequestHandlerInterface
{
    public function __construct(private HtmlRenderer $html) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->respond('@security_fixture/broken.html.twig');
    }
}
