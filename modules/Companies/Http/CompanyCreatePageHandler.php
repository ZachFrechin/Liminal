<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The form that mints a company: code (immutable afterwards) and name.
 */
final readonly class CompanyCreatePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(CompanyListHandler::PERMISSION);

        return $this->html->respond(
            '@companies/company_create.html.twig',
            [],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
