<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http\Pdf;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Database\Money\Cents;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Scope\CompanyIdentity;
use Liminal\Lib\Pdf\PdfRenderer;
use Liminal\Lib\Rendering\Translator;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Invoice\Totals\InvoiceTotalsService;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The invoice as a document. Every state prints: a validated invoice under
 * its definitive number, a draft under a PROFORMA watermark and a filename
 * that carries no number — the number does not exist yet, and the document
 * says so instead of pretending.
 *
 * The seller block comes from the company identity, the buyer block from
 * the thirdparty's own address; absent fields simply do not print —
 * content degrades, the identity screen is where completeness lives.
 */
final readonly class InvoicePdfHandler implements RequestHandlerInterface
{
    public function __construct(
        private PdfRenderer $pdf,
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private InvoiceTotalsService $totals,
        private ThirdpartyRepository $thirdparties,
        private CompanyIdentity $companies,
        private CompanyContext $context,
        private Translator $translator,
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

        $lines = $this->invoices->linesOf($invoice);

        $ventilation = [];
        foreach ($this->totals->totalsFor($invoice, $lines)->vatByRate as $rate => $cents) {
            $ventilation[] = ['rate' => $rate, 'amount' => Cents::toDecimal($cents)];
        }

        $filename = $invoice->getNumber() !== null
            ? $invoice->getNumber() . '.pdf'
            : sprintf('invoice-draft-%d.pdf', $id);

        return $this->pdf->respond('@invoice/pdf.html.twig', [
            'invoice' => $invoice,
            'lines' => $lines,
            'lineTotals' => $this->lineTotals($lines),
            'ventilation' => $ventilation,
            'seller' => $this->companies->identityOf($this->context->currentId()),
            'buyer' => $this->thirdparties->byId($invoice->getThirdpartyId()),
            '_pdf_watermark' => $invoice->isDraft() ? $this->translator->trans('invoice.pdf.proforma') : null,
        ], $filename);
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @return array<int, string> line id => its total excluding tax
     */
    private function lineTotals(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $id = $line->getId();

            if ($id !== null) {
                $totals[$id] = Cents::toDecimal(intdiv(
                    Cents::fromDecimal($line->getQuantity()) * Cents::fromDecimal($line->getUnitPrice()) + 50,
                    100,
                ));
            }
        }

        return $totals;
    }
}
