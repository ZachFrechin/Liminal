<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Rendering\HtmlRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The rendering test surface: a real page through the real layout — exactly
 * the single-dependency shape phase-5 page handlers will have.
 */
final readonly class PageHandler implements RequestHandlerInterface
{
    public function __construct(private HtmlRenderer $html) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->respond('@security_fixture/page.html.twig', [
            'headline' => 'Rendered through the stack',
        ]);
    }
}
