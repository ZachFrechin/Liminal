<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Invoice\Totals\InvoiceTotalsService;
use Liminal\Module\Order\Exception\OrderModuleException;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The second one-way door: a VALIDATED order becomes a DRAFT invoice, once.
 * Creating an invoice demands invoice.manage on top of order.manage — the
 * detail page computes the same conjunction so the button never 403s.
 *
 * ONE wrapInTransaction, deliberately: invoice, lines, totals and the
 * order's pointer commit together or not at all — four autocommit flushes
 * would leave an orphan draft with lines if the totals hook threw halfway,
 * and a retry would mint a second one. The explicit inner flushes are legal
 * inside the wrap (INSERTs join the open transaction) and assign ids
 * mid-wrap: getId() is non-null where the copy needs it.
 *
 * The invoice's totals come from ITS OWN hook (invoice.total.compute), not
 * copied from the order — a discount listener may treat quotes and bills
 * differently, and a listener throw cancels the whole conversion. That is
 * the point.
 *
 * After a throw inside the wrap the EntityManager is closed (the written
 * rule): session flash and redirect only, nothing more asked of the ORM.
 */
final readonly class OrderInvoiceHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private InvoiceRepository $invoices,
        private InvoiceTotalsService $invoiceTotals,
        private EntityManagerInterface $entityManager,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    /**
     * @throws HttpException as notFound() when no such order exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);
        $this->gate->authorize(OrderModule::MANAGE);
        $this->gate->authorize(InvoiceModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw OrderModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $order = $this->orders->byId($id);

        if ($order === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if ($order->isInvoiced()) {
            $session->set('error', 'order.form.already_invoiced');

            return $this->redirectTo('order.detail', ['id' => $id]);
        }

        if (!$order->isValidated()) {
            $session->set('error', 'order.form.not_validated');

            return $this->redirectTo('order.detail', ['id' => $id]);
        }

        $orderLines = $this->orders->linesOf($order);

        $invoiceId = $this->entityManager->wrapInTransaction(
            function () use ($order, $orderLines): int {
                // The conversion does not validate the invoice: it is born a
                // draft, dated today, and earns its INV number on its own.
                $invoice = new Invoice($order->getThirdpartyId(), new DateTimeImmutable('today'), null);

                $this->invoices->add($invoice);
                $this->entityManager->flush();

                $invoiceId = (int) $invoice->getId();

                foreach ($orderLines as $line) {
                    $this->invoices->addLine(new InvoiceLine(
                        $invoiceId,
                        $line->getPosition(),
                        $line->getLabel(),
                        $line->getQuantity(),
                        $line->getUnitPrice(),
                        $line->getVatRate(),
                    ));
                }

                $this->entityManager->flush();

                $invoice->refreshTotals(
                    $this->invoiceTotals->totalsFor($invoice, $this->invoices->linesOf($invoice)),
                );
                $order->markInvoiced($invoiceId);

                // wrapInTransaction flushes on the way out: the invoice, its
                // lines, its totals and the order's pointer are ONE commit.
                return $invoiceId;
            },
        );

        // Post-commit, both facts: the order's transition, and the birth of
        // an invoice. INVOICE_CREATED is a DELIBERATE cross-module fire —
        // the invoice module declared the name, the name is the contract,
        // and its stream must stay complete for any future consumer.
        $this->triggers->fire('ORDER_INVOICED', [
            'order_id' => $id,
            'number' => $order->getNumber(),
            'invoice_id' => $invoiceId,
        ]);
        $this->triggers->fire('INVOICE_CREATED', [
            'invoice_id' => $invoiceId,
            'thirdparty_id' => $order->getThirdpartyId(),
        ]);
        $session->set('success', 'order.form.invoiced');

        return $this->redirectTo('invoice.detail', ['id' => $invoiceId]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function redirectTo(string $route, array $parameters = []): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate($route, $parameters));
    }
}
