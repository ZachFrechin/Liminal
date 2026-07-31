<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\Exception\OrderModuleException;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Creates a draft in the working company. The party check is byId() on the
 * thirdparty repository: existence and company scope in one read — a party
 * from another company simply does not exist here, and the schema's foreign
 * key backs the answer.
 */
final readonly class OrderCreateSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private ThirdpartyRepository $thirdparties,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);
        $this->gate->authorize(OrderModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw OrderModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $form = OrderForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null || $form->issuedOn === null) {
            $session->set('error', $form->firstError() ?? 'order.form.issued_on_invalid');

            return $this->redirectTo('order.create');
        }

        if ($this->thirdparties->byId($form->thirdpartyId) === null) {
            $session->set('error', 'order.form.thirdparty_unknown');

            return $this->redirectTo('order.create');
        }

        $order = new Order($form->thirdpartyId, $form->issuedOn, $form->wantedOn);

        $this->orders->add($order);
        $this->orders->flush();

        // Post-commit: getId() is only non-null past the flush.
        $this->triggers->fire('ORDER_CREATED', [
            'order_id' => (int) $order->getId(),
            'thirdparty_id' => $form->thirdpartyId,
        ]);
        $session->set('success', 'order.form.created');

        return $this->redirectTo('order.detail', ['id' => (int) $order->getId()]);
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
