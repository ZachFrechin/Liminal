<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Database\Money\Cents;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Order\Entity\OrderLine;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Module\Order\Totals\OrderTotalsService;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One order of the working company: header, lines, totals — and the state
 * the page dresses for: a draft edits, a validated order offers the
 * conversion (to holders of invoice.manage too — a button that 403s is a
 * lie), an invoiced one links to the invoice it became.
 */
final readonly class OrderDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private Gate $allows,
        private OrderRepository $orders,
        private OrderTotalsService $totals,
        private ThirdpartyRepository $thirdparties,
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

        $canManage = $this->allows->allows(OrderModule::MANAGE);

        return $this->html->respond(
            '@order/order.html.twig',
            [
                'order' => $order,
                'lines' => $lines,
                'lineTotals' => $this->lineTotals($lines),
                'thirdpartyName' => $this->orders->thirdpartyNamesFor([$order->getThirdpartyId()])[$order->getThirdpartyId()] ?? null,
                'ventilation' => $ventilation,
                'canManage' => $canManage,
                'canInvoice' => $canManage && $this->allows->allows(InvoiceModule::MANAGE),
                'thirdparties' => $canManage && $order->isDraft() ? $this->thirdparties->activeForSelect() : [],
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
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
