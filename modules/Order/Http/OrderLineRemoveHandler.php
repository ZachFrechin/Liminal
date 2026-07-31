<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Order\Exception\OrderModuleException;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Module\Order\Totals\OrderTotalsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Removes one line from a draft and lets the totals follow. Line edit is
 * remove-plus-re-add for now — the recorded gap, shared with the invoice.
 */
final readonly class OrderLineRemoveHandler implements RequestHandlerInterface
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
     * @throws HttpException as notFound() when the order or the line is not of this company and order
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);
        $this->gate->authorize(OrderModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw OrderModuleException::sessionMissing();
        }

        $rawId = $request->getAttribute('id');
        $id = is_numeric($rawId) ? (int) $rawId : 0;
        $rawLine = $request->getAttribute('line');
        $lineId = is_numeric($rawLine) ? (int) $rawLine : 0;

        $order = $this->orders->byId($id);

        if ($order === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if (!$order->isDraft()) {
            $session->set('error', 'order.form.immutable');

            return $this->redirectToDetail($id);
        }

        $line = $this->orders->lineOf($order, $lineId);

        if ($line === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $this->orders->removeLine($line);
        $this->orders->flush();

        $order->refreshTotals($this->totals->totalsFor($order, $this->orders->linesOf($order)));
        $this->orders->flush();

        $this->triggers->fire('ORDER_UPDATED', ['order_id' => $id]);
        $session->set('success', 'order.form.line_removed');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('order.detail', ['id' => $id]));
    }
}
