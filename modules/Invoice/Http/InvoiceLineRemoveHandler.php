<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Invoice\Totals\InvoiceTotalsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Removes one line from a draft and lets the totals follow. Line edit is
 * remove-plus-re-add for now — the recorded gap.
 */
final readonly class InvoiceLineRemoveHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private InvoiceTotalsService $totals,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    /**
     * @throws HttpException as notFound() when the invoice or the line is not of this company and invoice
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);
        $this->gate->authorize(InvoiceModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw InvoiceModuleException::sessionMissing();
        }

        $rawId = $request->getAttribute('id');
        $id = is_numeric($rawId) ? (int) $rawId : 0;
        $rawLine = $request->getAttribute('line');
        $lineId = is_numeric($rawLine) ? (int) $rawLine : 0;

        $invoice = $this->invoices->byId($id);

        if ($invoice === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if (!$invoice->isDraft()) {
            $session->set('error', 'invoice.form.immutable');

            return $this->redirectToDetail($id);
        }

        $line = $this->invoices->lineOf($invoice, $lineId);

        if ($line === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $this->invoices->removeLine($line);
        $this->invoices->flush();

        $invoice->refreshTotals($this->totals->totalsFor($invoice, $this->invoices->linesOf($invoice)));
        $this->invoices->flush();

        $this->triggers->fire('INVOICE_UPDATED', ['invoice_id' => $id]);
        $session->set('success', 'invoice.form.line_removed');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('invoice.detail', ['id' => $id]));
    }
}
