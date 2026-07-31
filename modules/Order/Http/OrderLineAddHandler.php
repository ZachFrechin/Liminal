<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Order\Entity\OrderLine;
use Liminal\Module\Order\Exception\OrderModuleException;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Module\Order\Totals\OrderTotalsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds a line to a draft, then lets the totals follow through the
 * order.total.compute dispatch. Two flushes on purpose: the line must be
 * committed before linesOf() can see it; a failure between them leaves
 * totals one write behind, which the next line write repairs — stale
 * money on a DRAFT, never on a validated record.
 */
final readonly class OrderLineAddHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private OrderTotalsService $totals,
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

        if (!$order->isDraft()) {
            $session->set('error', 'order.form.immutable');

            return $this->redirectToDetail($id);
        }

        $body = $request->getParsedBody();
        $form = OrderLineForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null) {
            $session->set('error', $form->firstError());

            return $this->redirectToDetail($id);
        }

        $this->orders->addLine(new OrderLine(
            $id,
            $this->orders->nextPosition($order),
            $form->label,
            $form->quantity,
            $form->unitPrice,
            $form->vatRate,
        ));
        $this->orders->flush();

        $order->refreshTotals($this->totals->totalsFor($order, $this->orders->linesOf($order)));
        $this->orders->flush();

        $this->triggers->fire('ORDER_UPDATED', ['order_id' => $id]);
        $session->set('success', 'order.form.line_added');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('order.detail', ['id' => $id]));
    }
}
