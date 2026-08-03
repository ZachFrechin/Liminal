<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The orders of the working company, newest first, searchable by number and
 * by party name — the invoice list's shape with one badge more.
 */
final readonly class OrderListHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private Gate $allows,
        private OrderRepository $orders,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);

        $schema = OrderRepository::schema();
        $list = ListRequest::fromQueryParams($request->getQueryParams(), $schema);
        $page = $this->orders->pageOf($list);

        $thirdpartyIds = array_values(array_unique(array_map(
            static fn($order): int => $order->getThirdpartyId(),
            $page->items,
        )));

        return $this->html->respond(
            '@order/orders.html.twig',
            [
                'page' => $page,
                'list' => $list,
                'schema' => $schema,
                'thirdpartyNames' => $this->orders->thirdpartyNamesFor($thirdpartyIds),
                'canManage' => $this->allows->allows(OrderModule::MANAGE),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
