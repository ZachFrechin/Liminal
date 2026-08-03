<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The invoices of the working company, newest first, searchable by number
 * and by party name. The party column resolves in one query for the page;
 * a name missing from the map degrades to a dash — content, not wiring.
 */
final readonly class InvoiceListHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private Gate $allows,
        private InvoiceRepository $invoices,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);

        $schema = InvoiceRepository::schema();
        $list = ListRequest::fromQueryParams($request->getQueryParams(), $schema);
        $page = $this->invoices->pageOf($list);

        $thirdpartyIds = array_values(array_unique(array_map(
            static fn($invoice): int => $invoice->getThirdpartyId(),
            $page->items,
        )));

        return $this->html->respond(
            '@invoice/invoices.html.twig',
            [
                'page' => $page,
                'list' => $list,
                'schema' => $schema,
                'thirdpartyNames' => $this->invoices->thirdpartyNamesFor($thirdpartyIds),
                'canManage' => $this->allows->allows(InvoiceModule::MANAGE),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
