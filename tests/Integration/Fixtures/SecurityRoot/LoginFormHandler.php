<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Rendering\HtmlRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for phase 5's login page: the route the HTML error middleware
 * redirects a browser to on a 401.
 */
final readonly class LoginFormHandler implements RequestHandlerInterface
{
    public function __construct(private HtmlRenderer $html) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->respond('@security_fixture/form.html.twig');
    }
}
