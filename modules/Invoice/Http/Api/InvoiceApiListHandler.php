<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http\Api;

use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The invoices of the working company as JSON — the thirdparty api pattern:
 * same repository, same permission, same narrowing as the screens.
 */
final readonly class InvoiceApiListHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private ApiResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);

        $params = $request->getQueryParams();
        $page = is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1;
        $query = is_string($params['q'] ?? null) ? $params['q'] : null;

        return $this->responder->collection(
            $this->invoices->page($page, $query),
            static fn(Invoice $invoice): array => InvoicePayload::from($invoice),
        );
    }
}
