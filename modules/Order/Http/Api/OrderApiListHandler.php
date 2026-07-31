<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http\Api;

use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The orders of the working company as JSON — the thirdparty api pattern:
 * same repository, same permission, same narrowing as the screens.
 */
final readonly class OrderApiListHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private ApiResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);

        $params = $request->getQueryParams();
        $page = is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1;
        $query = is_string($params['q'] ?? null) ? $params['q'] : null;

        return $this->responder->collection(
            $this->orders->page($page, $query),
            static fn(Order $order): array => OrderPayload::from($order),
        );
    }
}
