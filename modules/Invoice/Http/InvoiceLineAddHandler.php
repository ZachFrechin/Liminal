<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Invoice\Totals\InvoiceTotalsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds a line to a draft, then lets the totals follow through the
 * invoice.total.compute dispatch. Two flushes on purpose: the line must be
 * committed before linesOf() can see it; a failure between them leaves
 * totals one write behind, which the next line write repairs — stale
 * money on a DRAFT, never on a validated record.
 */
final readonly class InvoiceLineAddHandler implements RequestHandlerInterface
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
     * @throws HttpException as notFound() when no such invoice exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);
        $this->gate->authorize(InvoiceModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw InvoiceModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $invoice = $this->invoices->byId($id);

        if ($invoice === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if (!$invoice->isDraft()) {
            $session->set('error', 'invoice.form.immutable');

            return $this->redirectToDetail($id);
        }

        $body = $request->getParsedBody();
        $form = InvoiceLineForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null) {
            $session->set('error', $form->firstError());

            return $this->redirectToDetail($id);
        }

        $this->invoices->addLine(new InvoiceLine(
            $id,
            $this->invoices->nextPosition($invoice),
            $form->label,
            $form->quantity,
            $form->unitPrice,
            $form->vatRate,
        ));
        $this->invoices->flush();

        $invoice->refreshTotals($this->totals->totalsFor($invoice, $this->invoices->linesOf($invoice)));
        $this->invoices->flush();

        $this->triggers->fire('INVOICE_UPDATED', ['invoice_id' => $id]);
        $session->set('success', 'invoice.form.line_added');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('invoice.detail', ['id' => $id]));
    }
}
