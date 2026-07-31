<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The empty draft form. The party select consumes the thirdparty module's
 * repository directly — the sanctioned one-direction dependency: an order
 * cannot exist without the party it serves.
 */
final readonly class OrderCreatePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);
        $this->gate->authorize(OrderModule::MANAGE);

        return $this->html->respond(
            '@order/order_create.html.twig',
            [
                'thirdparties' => $this->thirdparties->activeForSelect(),
                'today' => date('Y-m-d'),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
