<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Money\Cents;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Invoice\Totals\InvoiceTotalsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One invoice of the working company: header, lines, and the totals panel.
 *
 * One source rule for amounts: the three totals on screen come from the
 * STORED columns (what the draft froze at its last line write, what the
 * validated record carries forever); the per-rate ventilation comes from the
 * hook-wrapped totals service on the same lines. The two agree by
 * construction today — the ventilation gains its own storage the day the
 * first production listener lands.
 */
final readonly class InvoiceDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private Gate $allows,
        private InvoiceRepository $invoices,
        private InvoiceTotalsService $totals,
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

        return $this->html->respond(
            '@invoice/invoice.html.twig',
            [
                'invoice' => $invoice,
                'lines' => $lines,
                'lineTotals' => $this->lineTotals($lines),
                'thirdpartyName' => $this->invoices->thirdpartyNamesFor([$invoice->getThirdpartyId()])[$invoice->getThirdpartyId()] ?? null,
                'ventilation' => $ventilation,
                'canManage' => $this->allows->allows(InvoiceModule::MANAGE),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
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
