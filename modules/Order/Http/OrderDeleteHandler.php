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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes a DRAFT — its lines ride the schema's cascade. A validated or
 * invoiced order never disappears: the refusal is a flash, and there is no
 * force flag anywhere for a reason.
 */
final readonly class OrderDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
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

            return $this->responses->createResponse(302)
                ->withHeader('Location', $this->urls->generate('order.detail', ['id' => $id]));
        }

        $this->orders->remove($order);
        $this->orders->flush();

        $this->triggers->fire('ORDER_DELETED', ['order_id' => $id]);
        $session->set('success', 'order.form.deleted');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('order.list'));
    }
}
