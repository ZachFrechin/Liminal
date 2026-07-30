<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Companies\Administration\CompanyAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One company: the rename form, and the per-module state for THIS company —
 * declared, installed, enabled — as standing information. A module row
 * reading "not installed" is the durable version of the creation warning,
 * with the remedy right next to it.
 */
final readonly class CompanyDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private CompanyAdministration $companies,
        private ModuleManager $modules,
    ) {}

    /**
     * @throws HttpException as notFound() when no such company exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(CompanyListHandler::PERMISSION);

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $company = $this->companies->companyById($id);

        if ($company === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $enabled = $this->modules->enabledFor($id);
        $modules = [];

        foreach ($this->modules->overview() as $module) {
            $modules[] = [
                'name' => $module->name,
                'installed' => $module->installedVersion !== null,
                'enabled' => $enabled[$module->name] ?? false,
            ];
        }

        return $this->html->respond(
            '@companies/company.html.twig',
            ['company' => $company, 'modules' => $modules],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
