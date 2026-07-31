<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http\Api;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One invoice with its lines as JSON. byId() narrows to the working company:
 * a foreign id answers the same 404 as a missing one.
 */
final readonly class InvoiceApiDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private ApiResponder $responder,
    ) {}

    /**
     * @throws HttpException as notFound() when no such invoice exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $invoice = $this->invoices->byId($id);

        if ($invoice === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        return $this->responder->item([
            ...InvoicePayload::from($invoice),
            'lines' => array_map(
                InvoicePayload::line(...),
                $this->invoices->linesOf($invoice),
            ),
        ]);
    }
}
