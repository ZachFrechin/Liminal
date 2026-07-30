<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Rendering\HtmlRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The feedback family's proof surface: every component of the design system
 * rendered on one anonymous page, through the real layout and macros — the
 * markup here doubles as the documentation of the toast, tooltip and dialog
 * patterns until a production consumer earns them a macro.
 */
final readonly class DesignPageHandler implements RequestHandlerInterface
{
    public function __construct(private HtmlRenderer $html) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->respond('@security_fixture/design.html.twig', []);
    }
}
