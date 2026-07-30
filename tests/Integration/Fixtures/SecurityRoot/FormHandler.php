<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Rendering\HtmlRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A public page with a form: the surface that proves the anonymous first
 * write — rendering csrf_field() is what creates the session row and cookie.
 */
final readonly class FormHandler implements RequestHandlerInterface
{
    public function __construct(private HtmlRenderer $html) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->respond('@security_fixture/form.html.twig');
    }
}
