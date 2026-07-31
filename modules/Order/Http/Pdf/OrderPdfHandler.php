<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http\Pdf;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Database\Money\Cents;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Scope\CompanyIdentity;
use Liminal\Lib\Pdf\PdfRenderer;
use Liminal\Lib\Rendering\Translator;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Order\Entity\OrderLine;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Module\Order\Totals\OrderTotalsService;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The order as a document — the invoice pdf pattern with the order's
 * vocabulary: a wanted date instead of a due date, and two printable
 * numbered states (validated AND invoiced — an invoiced order remains a
 * confirmed order). A draft prints under the PROFORMA watermark.
 */
final readonly class OrderPdfHandler implements RequestHandlerInterface
{
    public function __construct(
        private PdfRenderer $pdf,
        private RequestGate $gate,
        private OrderRepository $orders,
        private OrderTotalsService $totals,
        private ThirdpartyRepository $thirdparties,
        private CompanyIdentity $companies,
        private CompanyContext $context,
        private Translator $translator,
    ) {}

    /**
     * @throws HttpException as notFound() when no such order exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $order = $this->orders->byId($id);

        if ($order === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $lines = $this->orders->linesOf($order);

        $ventilation = [];
        foreach ($this->totals->totalsFor($order, $lines)->vatByRate as $rate => $cents) {
            $ventilation[] = ['rate' => $rate, 'amount' => Cents::toDecimal($cents)];
        }

        $filename = $order->getNumber() !== null
            ? $order->getNumber() . '.pdf'
            : sprintf('order-draft-%d.pdf', $id);

        return $this->pdf->respond('@order/pdf.html.twig', [
            'order' => $order,
            'lines' => $lines,
            'lineTotals' => $this->lineTotals($lines),
            'ventilation' => $ventilation,
            'seller' => $this->companies->identityOf($this->context->currentId()),
            'buyer' => $this->thirdparties->byId($order->getThirdpartyId()),
            '_pdf_watermark' => $order->isDraft() ? $this->translator->trans('order.pdf.proforma') : null,
        ], $filename);
    }

    /**
     * @param list<OrderLine> $lines
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
