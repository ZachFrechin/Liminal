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
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Edits a draft's header: the party and the dates. A validated or invoiced
 * order refuses with a flash — the entity's own guard is defence in depth
 * behind this handler, never the user's error message.
 */
final readonly class OrderUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private ThirdpartyRepository $thirdparties,
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
        $form = OrderForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null || $form->issuedOn === null) {
            $session->set('error', $form->firstError() ?? 'order.form.issued_on_invalid');

            return $this->redirectToDetail($id);
        }

        if ($this->thirdparties->byId($form->thirdpartyId) === null) {
            $session->set('error', 'order.form.thirdparty_unknown');

            return $this->redirectToDetail($id);
        }

        $order->update($form->thirdpartyId, $form->issuedOn, $form->wantedOn);
        $this->orders->flush();

        $this->triggers->fire('ORDER_UPDATED', ['order_id' => $id]);
        $session->set('success', 'order.form.updated');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('order.detail', ['id' => $id]));
    }
}
