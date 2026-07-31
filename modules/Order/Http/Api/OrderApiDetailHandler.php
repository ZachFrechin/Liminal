<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http\Api;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One order with its lines as JSON. byId() narrows to the working company:
 * a foreign id answers the same 404 as a missing one.
 */
final readonly class OrderApiDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private ApiResponder $responder,
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

        return $this->responder->item([
            ...OrderPayload::from($order),
            'lines' => array_map(
                OrderPayload::line(...),
                $this->orders->linesOf($order),
            ),
        ]);
    }
}
