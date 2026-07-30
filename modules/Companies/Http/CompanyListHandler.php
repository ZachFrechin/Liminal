<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Companies\Administration\CompanyAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every company of the instance. Deliberately no user count: that would be a
 * read of another module's table, and there is no module→module dependency
 * mechanism on purpose.
 */
final readonly class CompanyListHandler implements RequestHandlerInterface
{
    public const string PERMISSION = 'companies.company.manage';

    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private CompanyAdministration $companies,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(self::PERMISSION);

        return $this->html->respond(
            '@companies/companies.html.twig',
            ['companies' => $this->companies->listAll()],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
